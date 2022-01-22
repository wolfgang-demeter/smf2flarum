# SMF to Flarum Converter
This will convert a SMF1-based forum to Flarum (v1.2.x).

This script is based on https://github.com/sriharshachilakapati/JGO-Flarum-Migration/

**ATTENTION: This will not run out of the box! You most certainly have to modify the `migrate.php` script to match your needs!**

## Installation
Do not clone this into your SMF or Flarum installation directories! This is a standalone script and should be installed as such.

```bash
mkdir smf2flarum-migrate
cd smf2flarum-migrate
git clone git@github.com:wolfgang-demeter/smf2flarum.git .
composer install
cp settings.sample.php settings.php
```
Edit `settings.php` with the configuration for your SMF and Flarum installations. Also [create an **API-Token**](https://github.com/flagrow/flarum-api-client#configuration) in your Flarum database. The Flarum-API will be used to migrate Avatars and Attachments.

You almost certainly have to edit some mapping-arrays and specific code-blocks to match your needs in `migrate.php`.

### MySQL
If you have a lot of data to migrate, it could be necessary to increase the `sort_buffer_size` of MySQL.
```bash
nano /etc/mysql/mysql.conf.d/dev-for-flarum-migrate.cnf
```
Add the `sort_buffer_size` setting.
```ini
[mysqld]
sort_buffer_size = 1M
```
Restart MySQL
```bash
systemctl restart mysql.service
```

## Migration
### Clear Avatars & Attachments
It might be a good idea to clear existing **avatars** and **assets/files** from Flarum beforehand.
```bash
rm -v /path/to/flarum/public/assets/avatars/*.png
rm -rfv /path/to/flarum/public/assets/files/*
```

### Run the script
Run the migration script. You have to confirm each step with **yes** or **no**.
```bash
php ./migrate.php
```

Run the complete migration without confirming each step beforehand.
```bash
php ./migrate.php --runall
```

If everything works as exptected, the output should look something like that:
```bash
Migrating categories: 8/8 (100%)
Migrating boards: 30/30 (100%)
Migrating users: 379/379 (100%)
Migrating topic 25330/25330 (100%) // Topic ID: 28012 // number of Posts: 2/2 (100%) // Slug: last-topic-migrated
Update last read for user with ID 761 on discussion with ID 26717
Update discussion & post counter for user with ID 770
```

## Required Extensions
The following extensions to Flarum are necessary for the migration script.

### Upload by FriendsOfFlarum
File Attachments from SMF get migrated to Flarum. Configure this extension before migration.

https://discuss.flarum.org/d/4154-friendsofflarum-upload-the-intelligent-file-attachment-extension

### User Bio by FriendsOfFlarum
Some user information from SMF will be stored as biography.

https://discuss.flarum.org/d/17775-friendsofflarum-user-bio

### Social Profile by FriendsOfFlarum
The user website from SMF will be stored in the Social Profile extension.

https://discuss.flarum.org/d/18775-friendsofflarum-social-profile

### Birthdays by datlechin
Users birthdays will be migrated to this extension.

https://discuss.flarum.org/d/29793-birthdays

### (optional) BBCode 5 Star Rating by me
If you have 5 Star Ratings (⭐⭐⭐⭐⭐) in your posts you want to migrate. Adjust the function `replaceBodyStrings()` in `migrate.php` according to your needs.

https://github.com/wolfgang-demeter/flarum-ext-bbcode-5star-rating
