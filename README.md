# Open Pastebin NG

Open Pastebin NG is an easy-to-use utility for sharing text and code through the web. It can handle normal plain text (text without format), Php, Ruby, bash, Python, Java/C/C++ and Pascal source code syntax highlight at this moment. See a feature list below for more details.
It’s based on [Open Pastebin](http://sourceforge.net/projects/openpastebin/). All you need is a web hosting with PHP and MySQL.
Open Pastebin NG is free software released under the GNU GPL license (see details [here](LICENSE)).
This software is a BETA release, wich means it’s still on development. Use it at your own risk.

## Features

- Simple and clean user interface
- Stores data in a MySQL database
- No installation needed and very simple configuration
- URLs are really long and harder to type, but it also gives you a short-URL using is.gd service
- Fast performance, no heavy images or media is transferred, just text
- Various programming languages syntax hightlihting supported
- Clean and simple PHP, HTML and XML code
- Easy to add new syntax support

## Screenshots

![Pastebin](https://raw.githubusercontent.com/emiliodevesadrums/opng/main/res/pastebin.png)

![Submit](https://raw.githubusercontent.com/emiliodevesadrums/opng/main/res/submit.png)

![Viewer](https://raw.githubusercontent.com/emiliodevesadrums/opng/main/res/view.png)

## Requirements

Open Pastebin-NG only requires a MySQL database and a PHP capable hosting.
It only needs about 46 Kb of storage.

## Installation

Download the ZIP file (look for it in the [Releases](https://github.com/emiliodevesadrums/opng/releases) section) and extract it to an empty directory.
Edit the config.php file and set your hosting details, for example:

	$ mysql_server = "localhost:3306";
	$ mysql_username = "anon";
	$ mysql_password = "";
	$ mysql_dbname = "opbdb";

Optionally, you can turn off the short url fetching as well (on v0.4 or higher), by setting this:

	$ short_url_enable="no"

Now, upload the files to your server.

## Usage
Load (http://www.example.com/index.php) on your browser changing the example domain for yours.
Select your type of text (plain or any source code) and type the text.
Press the «Submit» button. You’ll see the URLs where you can now read what you sent. You can also copy&paste the links into a email, chat, forum, IM…
After that you can send modifications to the code using the text box below the viewer.

## FAQ

*What is Open Pastebin NG?*

> It’s a software running on a server to let you share text. It’s intended to be used to share the source code that programmers write and it allows other people to debug it in a simple way.

*Can I use it to share plain text or anotations?*

> Yes, of course you can. Just select «Plain text» on the «Language» menu of the form.

*And what about .doc Microsoft Word files and such stuff?*

> I’m sorry, but Open Pastebin NG is not intended to allow users to upload files, just publish plain text or code.

*Not even images?*

> Nope.

*What is Open Pastebin NG like?*

> Open Pastebin NG may look like another pastebin.com clone, but your submissions are not listed in any site and only you will know the link where you can read it. Then you can share that link with the people you want, being shure that virtually no one else will see it.

*Virtually?*

>The URL that Open Pastebin NG will create for your submission is generated by some parameters that make it unique. Although the resulting URL is a really complex combination of random characters, it’s possible to reach a submission just trying different combinations of characters, but the posibilites are really low. As there usually are 256 different characters in any computer you would have to match a 34 characters-long id, wich makes a total of ( 256! / (256-34)! ) posibilities, actually: 765561793147593718096708780828290677223225202861182400491079923306332160000000000 posibilities. Would you really spend time trying? Good luck =). Optionally, you can use the «is.gd» short-url, wich will be really shorter.

*Ok, and where is my data stored?*

> In a MySQL database. It’s safe for three reasons:
>
>– Only admins can access it
>
>– Data is stored in binary mode
>
>– Data is stored by md5 hash, so admins won’t have too many clues to find a particular submission (see question above)


*What do I need to use Open Pastebin NG? Should I pay for a hosting plan?*

> You don’t really need it, you can use an existing public installation of Open Pastebin NG (search for them). You may want to download Open Pastebin NG and then upload it to your server in the case you want to make an intensive usage or use it for a long period of time (any installation may clean it’s database if admins want to make maintenance, it does not depend directly on Open Pastebin NG).

*Is Open Pastebin NG available in other languages?*

> Not yet, maybe in the future. Check for newer versions periodically or modify your OPNG files if you want to translate it. If you do, let me know and share it with the rest of us =).

*Can I download, modify and share again Open Pastebin NG?*

> Yes you can. Again, read the details of the GNU GPL license wich OPNG is released under. There’s an online copy of the license [here](LICENSE).

*I found a bug on Open Pastebin NG. Would you pay me any compensation? I lost my work!*

> No, I won’t. As highlighted at the top of this page, this software is BETA wich means it can still contain bugs or crash for any reason at some point. Known bugs are listed below this FAQ. If you find one, please send me your feedback.

*How can I add more code syntax highlight?*

> Edit rules.xml file. It’s really easy.

*Can I protect my entries with a password?*

>I’m sorry but, at this moment, Open Pastebin NG does not support password protected entries. This feature might be implemented in future versions.

*How do I clean the database?*

> You can doing using the admin panel. Password is hard-coded in the file login.php. The default password is 'demo' but you should change it to one of your own.

## Known Issues

There are zero known issues at this moment, but your feedback is really appreciated.

Based on Open Pastebin by
Ville Särkkälä - villeveikko@users.sourceforge.net

Released under GNU GENERAL PUBLIC LICENSE
Version 2, June 1991 -  or later

NOTE! THIS IS EXPERIMENTAL SOFTWARE MEANT ONLY FOR TESTING! USE AT YOUR OWN RISK!
I assume no responsibility for any damages if you choose to ignore the abovementioned warning
