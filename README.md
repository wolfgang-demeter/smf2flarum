**ATTENTION: THIS IS A WORK IN PROGRESS**

# SMF to Flarum Converter
This will convert a SMF1-based forum to Flarum (v1.0.x).

This script is based on https://github.com/sriharshachilakapati/JGO-Flarum-Migration/

**ATTENTION: This will not run out of the box! You most certainly have to modify this script to meet your needs!**

## Required Extensions
### Upload by FriendsOfFlarum
File Attachments from SMF get uploaded to Flarum.

https://discuss.flarum.org/d/4154-friendsofflarum-upload-the-intelligent-file-attachment-extension

### User Bio by FriendsOfFlarum
Some user information from SMF will be stored as biography.

https://discuss.flarum.org/d/17775-friendsofflarum-user-bio

### Social Profile by FriendsOfFlarum
The user website from SMF will be stored in the Social Profile extension.

https://discuss.flarum.org/d/18775-friendsofflarum-social-profile

### (optional) BBCode 5 Star Rating by me
If you have 5 Star Ratings (⭐⭐⭐⭐⭐) in your posts you want to migrate. Adjust the function `replaceBodyStrings()` in `migrate.php` according to your needs.

https://github.com/wolfgang-demeter/flarum-ext-bbcode-5star-rating
