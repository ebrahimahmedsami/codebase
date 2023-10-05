<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>



# Code Base

**Moltaqa** Laravel Project Codebase For Back-end Team
Which Has The Main Features like
Roles, Permissions, Wallet, Chat....

# Chat Module Vendors
1. [x] User
2. [x] Provider
3. [x] Driver

# Wallet Using Example

    auth('admin')->user()
        ->walletType(WalletTypeEnum::MONEY, WalletTransactionTypeEnum::DEPOSIT)
        ->walletSteps(10)
        ->walletTransactionReason(WalletTransactionReasonEnum::DEPOSIT_ORDER_AMOUNT)
        ->walletCreate();

# Send Notification Firebase Example

     (new SendFCM(User::class))
        ->sendForAdmin(true)
        ->sendForUsers(false)
        ->sendNotification('test title','test body',User::first());

# Send Notification Example

     ADMIN => Notification::sendnow($notifiables,new AdminNotification($notificationData)); >> will send via mail and database
     CLIENT => Notification::sendnow($notifiables,new ClientNotification($notificationData)); >> will send via firebase and database
     Notes
     Supported Mediums in BaseNotification Class => ['mail', 'sms', 'firebase','pusher', 'database']
     In BasNotification Class you can modify how each Medium Process or What it should transmit

# Setting Module Example Set And Get

    GET => setting(key:'general',default:'default data');
    SET => setting(key:'general',value:'data');
