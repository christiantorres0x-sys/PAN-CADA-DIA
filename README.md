Group Members
| Name | Role |
| --- | --- |
| Aizon, Kristian Dominic T.  | Backend Developer |
| Maali, Yasmien A. | Frontend Developer |
| Torres, Christian C. | Database, Backend Developer |

Tools and Software Used
| Category | Tool | Purpose |
| --- | --- | --- |
| Design | Figma | UI layout and prototyping |
| Front end | HTML, CSS, JavaScript | Pages, styling, and live form behavior (age and BMI preview) |
| Back end | PHP 8+ (PDO) | Application logic and database access |
| Database | MySQL / MariaDB | Data storage |
| Local server | XAMPP (Apache + MySQL) | Running the system locally |
| Database tool | phpMyAdmin | Importing and managing the database |
| Version control | Git, Github Desktop, GitHub | Source control and collaboration |
| Hosting | Byethehost | Deployment |
| Browser | Chrome, Edge, or Firefox | Running the system

Setup (local, using XAMPP)
1. Install and start the servers
Install XAMPP (PHP 8.0+).
Open the XAMPP Control Panel and Start both Apache and MySQL.
2. Put the project in the web root
Extract the zip.
Copy the folder to C:\xampp\htdocs\ and name it, for example, pan_cada_dia (on macOS/Linux: /opt/lampp/htdocs/ or /var/www/html/). Make sure index.php sits directly inside that folder.
3. Create the database
Go to http://localhost/phpmyadmin.


Click New, name the database pan_cada_dia, choose collation utf8mb4_general_ci (or utf8mb4_unicode_ci), then Create.


Select the new database → Import tab → choose setup.sql → Go.

 Or from the command line:


mysql -u root -p -e "CREATE DATABASE pan_cada_dia CHARACTER SET utf8mb4;"
mysql -u root -p pan_cada_dia < setup.sql
setup.sql creates all tables, one admin user, a sample feeding site, and a sample program. It is safe to re-run.
4. Configure the database connection
Open config.php and replace the hosting credentials at the top with your local ones:
define('DB_HOST', 'localhost');
define('DB_NAME', 'pan_cada_dia');
define('DB_USER', 'root');
define('DB_PASS', '');        // XAMPP's default root password is empty

5. Run it
Open http://localhost/pan_cada_dia/ in your browser (use the folder name you chose in step 2).


Test Accounts for Each User Role 
User 1
Username: isabel.pantaleon
Password: Isabel#Pan2026	

User 2
Username: welma.omboy
Password: Welma#Omb2026	

Administrator
Username: gilbert.rio
Password: Gilbert#Rio2026
