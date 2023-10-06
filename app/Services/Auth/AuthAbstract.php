<?php

namespace App\Services\Auth;

use App\Exceptions\Api\Auth\AuthException;
use App\Http\Requests\Api\Auth\ChangeMobileRequest;
use App\Http\Requests\Api\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\Auth\ForgetPasswordRequest;
use App\Http\Requests\Api\Auth\ResetPasswordRequest;
use App\Http\Requests\Api\Auth\SendOTPRequest;
use App\Http\Requests\Api\Auth\VerifyOTPRequest;
use App\Models\AuthenticatableOtp;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;


abstract class AuthAbstract
{
    use ApiResponseTrait;
    protected bool $loginRequireSendOTP;
    public $model;

    public function __construct(User $model)
    {
        $this->loginRequireSendOTP = config('global.login_require_otp');
        $this->model = $model;
    }

    /**
     * Login
     */
    public function login(FormRequest $request,$abilities = null){
        $request->authenticate();
        $user = $request->user();

        if(!$user->isActive())
            throw AuthException::accountStatusDeactive(['deactive' => [__("Account Deactive")]]);

        $user->access_token = $user->createToken('snctumToken',$abilities ?? [])->plainTextToken;
        $this->addTokenExpiration($user->access_token);

        if($this->loginRequireSendOTP)
            return $this->handelOTPMethod($user);

        return $user;
    }


    public function sendOTP(SendOTPRequest $request){
        $user = $this->model::query()->whereMobile($request->mobile)->first();
        if(is_null($user))
            throw AuthException::userNotFound(['not_found' => [__("Data Not Found")]]);
        return $this->handelOTPMethod($user);
    }


    public function resendOTP(Request $request)
    {
        $user = $request->user();
        $user->access_token = $request->bearerToken();
        return $this->handelOTPMethod($user);
    }

    /**
     * verify OTP.
     *
     * @return JsonResponse
     */
    public function verifyOTP(VerifyOTPRequest $request): JsonResponse{
        $user = $request->user()->load('latestOTPToken');
        if (is_null($user->latestOTPToken))
            throw AuthException::otpNotGenerated(['genration_failed' => [__("Failed Operation")]]);
        if ($user->latestOTPToken?->isValid()) {
            if ($request->code == $user->latestOTPToken->code) {
                $user->latestOTPToken->active = false;
                $user->latestOTPToken->save();
                if (!$user->email_verified_at) {
                    $user->email_verified_at = now();
                    $user->save();
                }
                return $this->respondWithSuccess(__("Successfull Operation"));
            }

            return $this->setStatusCode(422)->respondWithError(__("Code Not Matched"));

        }
        return $this->setStatusCode(422)->respondWithError(__("Code Expired"));

    }

    /**
     * forget password.
     *
     * @return JsonResponse
     */
    public function forgetPassword(FormRequest $request,$abilities = null){
        if(!( $request instanceof ForgetPasswordRequest))
            throw AuthException::wrongImplementation(['wrong_implementation' => [__('Wrong Implementation')]]);

        $user = $this->model::whereMobile($request->mobile)->first();
        if (is_null($user))
            throw AuthException::userNotFound(['unauthorized' => [__("Unauthorized")]]);

        $user->access_token = is_null($user->currentAccessToken()) ? $user->createToken('snctumToken', $abilities ?? [] )->plainTextToken : $user->currentAccessToken();
        return $this->handelOTPMethod($user);
    }

    /**
     * change password
     *
     * @return JsonResponse
     */
    public function changePassword(ChangePasswordRequest $request,$abilities = null):JsonResponse
    {
        $user = $request->user();
        if (is_null($user))
            throw AuthException::userNotFound(['not_found' => [__("Data Not Found")]]);
        if (Hash::check($request->old_password, $user->password)) {

            $user->password = Hash::make($request->password);
            $user->save();

            $user->currentAccessToken()->delete();

            $user->access_token = $user->createToken('snctumToken', $abilities ?? [] )->plainTextToken;
            $this->addTokenExpiration($user->access_token);
            return $this->respondWithArray(array("access_token" => $user->access_token));

        } else {
            return $this->setStatusCode(422)->respondWithError(__("Current Password Wrong"));
        }
    }

    /**
     * reset password.
     *
     * @return JsonResponse
     */
    public function resetPassword(ResetPasswordRequest $request,$abilities = null): JsonResponse{
        $user = $request->user();
        if(is_null($user))
            throw AuthException::userNotFound(['not_found' => [__("Data Not Found")]]);

        $user->password = Hash::make($request->password);
        $user->save();
        $user->currentAccessToken()->delete();
        $user->access_token = $user->createToken('snctumToken', $abilities ?? [])->plainTextToken;
        $this->addTokenExpiration($user->access_token);
        return $this->respondWithArray(array("access_token" => $user->access_token));
    }


    public function logout(Request $request){

        $request->user()->currentAccessToken()->delete();
    }

    /**
     * change user mobile.
     *
     * @return User
     */
    public function changeMobile(ChangeMobileRequest $request){
        $user = $request->user();
        if(is_null($user))
            throw AuthException::userNotFound(['not_found' => [__("Data Not Found")]] );
        $user->update([
            'mobile' => $request->mobile,
        ]);
        return $this->handelOTPMethod($user);
    }

    public function profile(Request $request){
        $user = $request->user();
        if(is_null($user))
            throw AuthException::userNotFound(['not_found' => [__("Data Not Found")]] );
        return $user;
    }

    protected function handelMobileOTP($user)
    {
        $user->loadMissing('latestOTPToken');
        $fixedOTPNumbers = json_decode(Storage::disk('local')->get('fixed_otp_numbers.json'),true);
        $sendSMS = false;
        $createRecord = false;
        $fixedOTP = false;

        if (in_array($user->mobile, $fixedOTPNumbers))
            $fixedOTP = true;
        if (is_null($user->latestOTPToken) || !$user->latestOTPToken->isValid()) {
            $user->OTP = $fixedOTP ? "1234" : randomCode(4, 1);
            $sendSMS = true;
            $createRecord = true;
        } else {
            $user->OTP = $user->latestOTPToken->code;
            $sendSMS = true;
        }

        if ($sendSMS) {
            $message = __("otp_message", ['code' => $user->OTP]);
            $sms = sendSMS($message, $user->mobile);
            $user->sms = $sms["sms"];

            if ($createRecord && ($sms['sms'])) {
                $user->OTPTokens()->save(new AuthenticatableOtp([
                    'code' => $user->OTP,
                ]));
            }
        }
        return $user;
    }

    protected function handelMailOTP($user)
    {
        $user->loadMissing('latestOTPToken');
        $fixedOTPMails = json_decode(Storage::disk('local')->get('fixed_otp_emails.json'),true);
        $sendMail = false;
        $createRecord = false;
        $fixedOTP = false;

        if (!is_null($fixedOTPMails) && in_array($user->email, $fixedOTPMails))
            $fixedOTP = true;


        if (is_null($user->latestOTPToken) || !$user->latestOTPToken->isValid()) {
            $user->OTP = $fixedOTP ? "1234" : randomCode(4, 1);
            $sendMail = true;
            $createRecord = true;
        } else {
            $user->OTP = $user->latestOTPToken?->code;
            $sendMail = true;
        }

        if ($sendMail) {
            $user->mailed = sendOtpMail($user->OTP, $user->email);
            if ($createRecord && $user->mailed) {
                $user->OTPTokens()->save(new AuthenticatableOtp([
                    'code' => $user->OTP,
                ]));
            }
        }

        return $user;
    }

    protected function handelOTPMethod($user)
    {
        $token = PersonalAccessToken::findToken($user->access_token);

        if(is_null($token))
            throw AuthException::userNotFound(['unauthorized' => [__('Unauthorized')]],401);

        return $user->hasRole(['super-admin','admin']) ? $this->handelMailOTP($user) : $this->handelMobileOTP($user);
    }


    protected function addTokenExpiration($accessToken): void
    {
        $expirationTime = Carbon::now()->addDays(90);
        $personalAccessToken = PersonalAccessToken::findToken($accessToken);
        $personalAccessToken->expires_at = $expirationTime;
        $personalAccessToken->save();
    }


    protected function isTokenExpired($personalAccessToken)
    {
        $isExpired = false;
        $expirationTime = $personalAccessToken->expires_at;
        if ($expirationTime == null)
            $isExpired = true;
        if ($expirationTime instanceof Carbon && $expirationTime->isPast())
            $isExpired = true;
        return $isExpired;
    }

    public function deleteAccount(Request $request){
        $user = $request->user();
        if(is_null($user))
            throw AuthException::userNotFound(['unauthorized' => [__('Unauthorized')]],401);
        $user->tokens()->delete();
        $user->delete();
        $this->respondWithSuccess(__('Deleted Successfully'));
    }

    abstract public function register(FormRequest $request,$abilities = null);
}
