# Open Pastebin NG

**Open Pastebin NG** is an easy-to-use utility for sharing text and code through the web. It supports plain text as well as syntax highlighting for **PHP, Ruby, Bash, Python, Java/C/C++ and more**. See the feature list below for more details.

This project is based on **[Open Pastebin](http://sourceforge.net/projects/openpastebin/)**. All you need is a **web server with PHP and MySQL**.
Open Pastebin NG is **free software** released under the **[GNU GPL license](LICENSE)**.

⚠️ **This software is in BETA stage**, wich means it is still under development. Use it at your own risk.

---

## ✨ Features
- ✅ Simple and clean user interface
- ⚡️ Fast performance (no heavy images or media, just text)
- 🎨 Syntax highlighting for multiple programming languages
- 🔓 Secure authentication system for administrative actions and role-based access control
- 👤 User management: **Admin**, **Registered User**, and **Guest**  
- 🛡️ CSRF protection for sensitive actions
- 📂 Entries are stored in a **MySQL database**

---

## 🖼️ Screenshots

### 📌 Homepage (List of Pastes)
![Homepage](screenshots/homepage.png)

### 📌 Create a New Paste
![New Paste](screenshots/create_paste.png)

### 📌 View a Paste with Syntax Highlighting
![View Paste](screenshots/view_paste.png)

### 📌 Edit a Paste
![View Paste](screenshots/edit_paste.png)

### 📌 Admin Panel
![Admin Panel](screenshots/admin_panel.png)

---

## 🛠️ Requirements
To run Open Pastebin NG, you need:

- **PHP 8.2.35**, **8.3.36**, **8.4.27** or **8.5.12** or higher ⚠️ *(updated in v0.8 — see the "PHP security releases" note below)*
- **MySQL 5.7** or MariaDB
- A web server (**Apache, Nginx or similar**)

### 🔐 PHP security releases note (October 2026 — "PHP2Shell")

In October 2026 the PHP project disclosed several critical vulnerabilities affecting the **PHP engine itself**, which impact any Open Pastebin NG deployment running an affected PHP version:

| CVE | Type | Status | Fixed versions |
|---|---|---|---|
| **CVE-2026-109618** | Use-after-free (OOM/`longjmp()` handling) → **RCE** | Patched (2026-10-21), actively exploited | 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 |
| **CVE-2026-109619** | Use-after-free (stale global state) → **RCE** | Patched (2026-10-21), actively exploited | 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 |
| **CVE-2026-115560** | **RCE** via `session.upload_progress` + runtime `serialize_handler` change | **Unpatched** at time of writing | TBD |

**You must run a patched PHP version before exposing OPNG to the internet.** Additionally apply the official mitigation for CVE-2026-109619/115560 (Open Pastebin NG does **not** use file uploads or upload progress, so this can be disabled at no cost):

```ini
; php.ini (or, preferred, administratively via php-fpm pool / Apache)
session.upload_progress.enabled = 0
```

Examples for your server:

- **PHP-FPM** (recommended, not overridable by `ini_set()`): in your pool config:
  `php_admin_flag[session.upload_progress.enabled] = off` — then perform a **full restart** of the workers (not a reload).
- **Apache with mod_php**: OPNG ships a hardened `src/.htaccess` that disables `session.upload_progress` automatically.
- **Nginx + PHP-FPM**: set the pool flag above; block direct access to sensitive files with:
  ```nginx
  location ~ /(?:config\.php|database\.php|rules\.xml)$ { deny all; }
  ```

**Verification** (do it against the HTTP SAPI, not the CLI — `php -i` reads a different config):

```bash
php-fpm -v                          # confirm the patched binary actually serving traffic
php-fpm -i | grep session.upload_progress.enabled   # should print "0"
grep -Rn "ini_set.*serialize_handler" src/           # expected: no matches in OPNG
```

---

## 🚀 Installation
Follow this steps to install Open Pastebin NG:

1️⃣ **Clone the repository**

	git clone https://github.com/emilio-devesa/opng.git
	cd opng

2️⃣ **Configure the database**
Edit config.php and set your database credentials, for example:

	$mysql_server = "localhost";
	$mysql_username = "root";
	$mysql_password = "yourpassword";
	$mysql_dbname = "opng";

3️⃣ **Run the application**
- If using **Apache**, place the project in `htdocs` or configure a virtual host.
- If using **Nginx**, configure the root directory in your Nginx config.
- Open `http://localhost/` in your browser.

---

## 📌 Usage
From the homepage you can:

- 📝 **Create a new paste**: Enter your text or code, select a language and submit.
- 🔍 **View and edit a paste**: Click on the topic you want to view/edit
- 🛠️ **Manage pastes (Admin panel)**: Log in to `admin/admin.php` to drop entries or delete the entire database.

For security reasons, access to **admin features** is restricted to administrators.

---

## 🔒 User Roles & Permissions
👤 Guest
- Can view public pastes
- Can create pastes (anonymous pastes)
- Cannot delete any pastes

📝 Registered User
- Can create and manage their own pastes
- Can delete only their own pastes
- Cannot access the admin panel

🛠️ Administrator
- Has full control over the system
- Can delete any paste (even from other users)
- Can empty the database via the admin panel

---

## ❓FAQ

### 🔷 Is it possible to share files, images, or binary files?
No, **Open Pastebin NG** is designed to share plain text and source code only. It does not support file uploads, images, or binary files.

### 🔷 How many pastes can I create? Is there any limitation?
There is no built-in limit on the number of pastes you can create. However, limitations may exist depending on:

- Your **server storage capacity**
- **Database size restrictions** set by your hosting provider

### 🔷 Where is my data stored? Is it private and safe?
Your pastes are stored in a **MySQL database** on your web server. Security depends on:

- Your **server configuration** (firewalls, database access, etc.)
- Proper use of **server permissions**
- Whether you enable **HTTPS** for secure connections

By default, **pastes are public** and **Open Pastebin NG does not support password-protected pastes** at the moment.
If this is an essential feature, consider submitting a **feature request**.
However, **registered users** can manage and delete their own pastes.

### 🔷 Is Open Pastebin NG available in more languages?
Yes! Open Pastebin NG supports multiple languages.
Currently available:
- 🇬🇧 English
- 🇪🇸 Spanish
- 🇩🇪 German
- 🇫🇷 French
- 🇵🇹 Portuguese
- 🇨🇳 Chinese

### 🔷 Can I download, fork, modify, and/or share again Open Pastebin NG?
Yes! **Open Pastebin NG** is **free and open-source software**, licensed under the **[GNU GPL](LICENSE)**.
You can:

- **Download** and install it on your server
- **Modify** it to fit your needs
- **Fork** the project and contribute improvements
- **Share** your version with the community

### 🔷 I found a bug and I lost my work!
I'm sorry to hear that! Please note that **OPNG is still in BETA stage and under development**. Please report the issue so we can fix it.

### 🔷 How can I uninstall OPNG and/or delete all data stored?
To completely remove Open Pastebin NG, follow these steps:

1. **Empty the database** from the administration panel
2. **Delete all files** from your server
3. **Clear your browser cache** if neccesary

This will remove **all pastes and files permanently**.

🚀 **Need more help?** Feel free to open an issue or reach out.

---

## ✅ Known Issues
Currently, there are no known issues. However, as this is a **BETA release**, some unexpected bugs may still exist.

---

🔷 **Found a bug?**
If you encounter any issue, please report it by:

- Opening an issue on Github
- Submitting a pull request with a fix

Your feedback is highly appreciated!

---

## 📜 License
Released under [GNU GENERAL PUBLIC LICENSE](LICENSE)
Version 2, June 1991 -  or later

You are free to use, modify, and distribute this software under the terms of the GPL.
NOTE! THIS IS EXPERIMENTAL SOFTWARE MEANT ONLY FOR TESTING! USE AT YOUR OWN RISK!
I assume no responsibility for any damages if you choose to ignore the abovementioned warning

---

## 🛠️ Credits & Acknowledgments
This project is based on **Open Pastebin**, originally developed by:
**Ville Särkkälä** - villeveikko@users.sourceforge.net
