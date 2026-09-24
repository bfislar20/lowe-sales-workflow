# Forecast upload login setup

The forecast upload page reads its password hash from `config/forecast-admin.php`.
That private file is ignored by Git and must be present on the server before the
page can accept logins. The page returns a configuration message until it exists.

1. Choose a new, unique password. The old password was committed to this public
   repository and must no longer be used.
2. In a PHP command line, run:

   ```sh
   php -r 'echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), PHP_EOL;'
   ```

   Type the new password, press Enter, and copy only the resulting hash.
3. On SiteGround, copy `config/forecast-admin.example.php` to
   `config/forecast-admin.php`, replace the placeholder with the hash, and
   keep this private file out of GitHub. Limit its file permissions to the
   web server account where possible.
4. Sign out and test the new password on staging before updating production.

Changing the hash invalidates existing forecast admin sessions. Do not upload
the Lowe Master workbook to GitHub; upload it through the protected page.
