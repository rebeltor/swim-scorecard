# Swim Scorecard: install guide

Swim Scorecard is a phone-friendly web app for scoring a two-team swim dual meet.
The organizer signs in with Google and starts a scorecard (for example "JD vs Bville"),
then sends the scorer link to the people entering places. Anyone can follow the score live.

There is no database. Each scorecard is saved as a small JSON file on your server.

| Who | How they get in | What they can do |
| --- | --- | --- |
| Organizer (an address in `admin_emails`) | Signs in with Google | Start scorecards, edit any scorecard, get both links, lock and unlock the scorecards they created |
| Scorer | Opens the scorer link. No sign-in | Enter places on that one scorecard. Their phone remembers it |
| Everyone else | Main page or the view-only link | See the list of scorecards and follow the score live |

## What you need

- A Linux web server running Apache 2.4 or Nginx
- PHP 8.1 or newer (mod_php or PHP-FPM). Only built-in PHP features are used, so there is
  nothing to install with Composer, npm or a package manager.
- The server must be able to make outgoing HTTPS requests to Google for sign-in. PHP needs
  either the curl extension or `allow_url_fopen` turned on (it is on by default).
- A Google account to create the sign-in credentials (step 5).
- HTTPS is recommended. Phones only allow the **Copy link** and **Share** buttons on HTTPS
  sites. On plain HTTP, people can still press and hold the link to copy it.

## 1. Upload the files

Copy the `swim-scorecard` folder into your web root. For example:

```bash
sudo unzip swim-scorecard.zip -d /var/www/html/
```

The app is then at `https://your-server/swim-scorecard/`. Rename the folder to change that
address, or copy the folder's contents into the web root to run it as the whole site.

## 2. Check the PHP version

```bash
php -v
```

It must say 8.1 or higher.

## 3. Let the web server save scorecards

The user your web server runs as needs to be able to write to the `data` folder.

| Server | Web server user |
| --- | --- |
| Debian / Ubuntu (Apache or Nginx) | `www-data` |
| Amazon Linux, RHEL, Rocky, Alma with Apache | `apache` |
| Amazon Linux, RHEL, Rocky, Alma with Nginx | `nginx` |

```bash
sudo chown -R www-data:www-data /var/www/html/swim-scorecard/data
sudo chmod 770 /var/www/html/swim-scorecard/data
```

**SELinux** (RHEL family with SELinux enforcing) also needs the folder marked as writable:

```bash
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/html/swim-scorecard/data(/.*)?"
sudo restorecon -Rv /var/www/html/swim-scorecard/data
```

**Recommended: keep scorecards outside the web root.** That way they can never be downloaded
directly, even if step 4 is missed.

```bash
sudo mkdir -p /var/lib/swim-scorecard
sudo chown www-data:www-data /var/lib/swim-scorecard
sudo chmod 770 /var/lib/swim-scorecard
```

Then open `config.php` and change the `data_dir` line to:

```php
'data_dir' => '/var/lib/swim-scorecard',
```

With SELinux, mark that folder `httpd_sys_rw_content_t` as shown above. If your PHP setup uses
`open_basedir`, add the folder to it.

## 4. Block direct access to the data and code folders

Anyone who can list or download files in the `data` folder could find every scorecard link.

**Apache:** the app ships with `.htaccess` files that block this, but Apache only reads them
when the site allows it. Make sure your site configuration has:

```apache
<Directory /var/www/html/swim-scorecard>
    AllowOverride All
</Directory>
```

Then reload Apache: `sudo systemctl reload apache2` (Debian/Ubuntu) or
`sudo systemctl reload httpd` (Amazon Linux/RHEL).

**Nginx:** Nginx ignores `.htaccess` files. Add the rules from `nginx-example.conf` (included
in the zip) to your server block, then run `sudo nginx -t && sudo systemctl reload nginx`.

## 5. Set up Google sign-in

Only organizers sign in, and only to start scorecards (or to edit one without its scorer link).
Scorers and parents never sign in.

1. Go to <https://console.cloud.google.com/> and create a project (or pick an existing one).
2. Open **Google Auth Platform** (called **APIs & Services > OAuth consent screen** in some
   accounts). Set the app name to "Swim Scorecard" and the audience to **External**.
   Under **Audience**, either add torrancejones@gmail.com as a **test user** or publish the app.
   The app only asks for the email address, so Google doesn't require a review.
3. Open **Clients** (or **APIs & Services > Credentials**) and create an **OAuth client ID**
   with the application type **Web application**.
4. Under **Authorized redirect URIs**, add the full address of `auth.php`, for example
   `https://your-server.example.com/swim-scorecard/auth.php`. It must be HTTPS and match exactly.
5. Copy the **Client ID** and **Client secret** into `config.php`, and set `google_redirect_uri`
   to the same address you entered in step 4:

   ```php
   'google_client_id' => '1234567890-abc123.apps.googleusercontent.com',
   'google_client_secret' => 'GOCSPX-...',
   'google_redirect_uri' => 'https://your-server.example.com/swim-scorecard/auth.php',
   'admin_emails' => ['torrancejones@gmail.com'],
   ```

   `admin_emails` already contains torrancejones@gmail.com. Add more addresses to let other
   organizers start scorecards.

Until these values are filled in, the main page says Google sign-in isn't set up, and nobody can
start a scorecard. Following scores still works.

## 6. Test it

1. Open `https://your-server/swim-scorecard/api.php?action=health`.
   You should see `"ok":true`. If `dataFolderWritable` is `false`, go back to step 3.
   `googleSignInConfigured` should be `true` once step 5 is done.
2. Open `https://your-server/swim-scorecard/data/probe.txt`.
   You should get **403 Forbidden** or **404 Not Found**. If you can read the message in the
   file, go back to step 4. (Skip this check if you moved the data folder in step 3.)
3. Open `https://your-server/swim-scorecard/` on your phone, tap **Sign in with Google**, then
   name a scorecard and enter a place.

## Using the app

- **Main page:** lists every scorecard, newest first. Anyone can open one to follow the score.
  It opens view-only unless you're a signed-in organizer or that phone has the scorer link.
- **Start (organizers):** tap **Sign in with Google**, type a name for the scorecard, like
  "JD vs Bville", and tap **Start scoring**.
- **Enter places:** open an event and tap the team (**JD** or **Opp** unless you've renamed them),
  **Tie** or **DQ** for each place. Tap a selected button again to clear it. **Next event** moves
  down the meet.
- **Scorers per team:** only 3 swimmers and 2 relays per team can score in an event. If a team's
  4th swimmer (or 3rd relay) is entered, that button turns grey and crossed out, the place gets no
  points, and everyone behind moves up a place. If it happened in the scoring places, a message
  explains it so the scorer can fix a wrong tap. Change the limits under the gear icon > Points.
- **6 or 8 lanes:** events start with 6 places (4 for relays). When a DQ or a team's extra swimmer
  pushes a swimmer into the points, another place appears automatically, so 8- and 10-lane meets
  work with no setting. **Add a place** adds one by hand.
- **Ties:** tap **Tie** on each tied place. For a tie for 4th, tap Tie in 4th and 5th. A two-way
  tie counts as one swimmer from each team, and they split the points. If both tied swimmers were
  on the same team, change it in the box that appears under the tie. For three or more tied
  swimmers, pick how many were from your team.
- **DQ:** tap **DQ** in the place where the disqualified swimmer finished. Everyone behind moves
  up a place. For example, if 5th is a DQ, whoever finished 6th gets 5th-place points. If that
  leaves a scoring place open, the app asks who finished next.
- **Not enough swimmers:** leave the extra places empty. Places nobody fills aren't awarded.
- **Share:** tap the share icon at the top. Organizers and scorers see two links:
  - **Scorer link**, with a QR code: send it to the people entering places. Anyone with it can
    enter places without signing in, and their phone remembers it.
  - **View-only link**: for parents and fans. It shows the live score but nothing can be changed.

  Changes show up on everyone's phone within a few seconds.
- **Teams:** tap the gear icon > **Teams** to rename the teams (up to 10 characters, since the
  names go on the buttons) and pick their colors. New scorecards use JD in red and Opp in orange,
  set in `config.php`.
- **Events and points:** the gear icon also has **Events** (rename, add, remove or reorder events,
  turn relay scoring on or off) and **Points** (points per place and scorers per team). The default
  is 6-4-3-2-1 for individual events and diving, and 8-4-2 for relays.
- **Lock:** the organizer who created a scorecard can lock it from the gear icon when the meet is
  over. Nobody can change anything while it's locked, including scorers with the link. The same
  organizer unlocks it from the gear icon or the **Unlock** button on the scorecard. Locked
  scorecards are marked on the main page.
- **Scorecards you've opened** are listed on that phone's start screen.

## Settings in config.php

| Setting | Default | What it does |
| --- | --- | --- |
| `data_dir` | the `data` folder | Where scorecards are saved |
| `max_scorecards` | 5000 | New scorecards are refused once this many files exist |
| `poll_seconds` | 3 | How often an open scorecard checks for changes from other people |
| `google_client_id` | placeholder | OAuth client ID from Google Cloud (step 5) |
| `google_client_secret` | placeholder | OAuth client secret from Google Cloud (step 5) |
| `google_redirect_uri` | placeholder | Address of `auth.php`, exactly as entered in Google Cloud |
| `admin_emails` | torrancejones@gmail.com | Google accounts allowed to start scorecards and edit any scorecard |
| `team_names` | JD, Opp | Team names for new scorecards |
| `team_colors` | `#C8102E` (red), `#F58220` (orange) | Team colors for new scorecards |
| `scorers_per_team` | 3 individual, 2 relay | How many from each team can score in an event (0 for no limit) |

## Good to know

- **Who can change a scorecard:** signed-in organizers, and anyone with that scorecard's
  scorer link. The scorer link contains a random 16-character key, so treat it like a password
  and send it only to scorers. If a scorer link gets out, open the scorecard's `.json` file in
  the data folder, delete the `"editKey"` entry and save. The old link stops working, and a new
  scorer link is made the next time an organizer opens the scorecard.
- **What's public:** the main page shows every scorecard's name and when it last changed, and
  anyone can open one to watch the score. To keep scores private as well, put the folder behind
  your web server's password protection (HTTP basic authentication).
- **Sign-in:** organizers stay signed in on that phone for 30 days. Sign out from the bottom
  of the start form. The sign-in is stored in a cookie signed with a key the app creates in the
  data folder (`auth-secret.key`). Deleting that file signs everyone out.
- **Several people at once:** saves are queued and applied one at a time on the server, so two
  people entering places at the same moment don't overwrite each other. If two people change
  the same place, the last change wins.
- **Lost connection:** places entered while offline stay on the phone and are sent when the
  connection returns. The header shows "Offline, will retry" until then.
- **Backups:** each scorecard is one `.json` file in the data folder. Copy the folder to back it
  up. To remove old scorecards, delete their `.json` files (and the matching `.lock` files).
- **Load balancers and Elastic Beanstalk:** scorecards are files on the server's own disk. On
  a platform that replaces servers on redeploy or runs several servers behind a load balancer,
  such as Elastic Beanstalk, scorecards can disappear and servers won't see each other's
  changes. Run the app on a single server with a persistent disk, or point `data_dir` at shared
  storage such as Amazon EFS.
- **Updating:** replace every file except `config.php` and the data folder.

## Troubleshooting

| What you see | What to do |
| --- | --- |
| "The web server cannot save to the data folder" | Repeat step 3 for the folder in `data_dir` |
| Main page says "Google sign-in isn't set up" | Fill in the three Google values in `config.php` (step 5) |
| Google shows "Error 400: redirect_uri_mismatch" | The redirect URI in Google Cloud must match `google_redirect_uri` exactly, including `https` and the folder name |
| Google shows "Access blocked" or says you're not a test user | Add your address as a test user in Google Auth Platform > Audience, or publish the app |
| "That Google account can't start scorecards" | Sign in with an address listed in `admin_emails`, or add the address there |
| "Google sign-in didn't work" | Check the PHP error log for `Google sign-in failed`. Usual causes: a wrong client secret, or the server can't reach Google |
| A scorer can't enter places | They opened the view-only link or the main page. Send them the scorer link |
| "Swim Scorecard needs PHP 8.1 or newer" | Upgrade PHP |
| "Something went wrong on the server" | Check the PHP or web server error log |
| Copy link doesn't copy | The site isn't on HTTPS. Press and hold the link to copy it, or add HTTPS |
| Other people's places don't show up | Check the phone's connection. The header says "Offline, will retry" when the server can't be reached |

## What's in the folder

```
swim-scorecard/
  index.php                 The app page
  api.php                   Saves and loads scorecards
  auth.php                  Google sign-in (this is the OAuth redirect URI)
  config.php                Your settings, including the Google sign-in values
  src/                      Server code (blocked from the web)
  data/                     Saved scorecards (blocked from the web)
  assets/css/app.css        Styles (built with Tailwind CSS)
  assets/js/app.js          The app (Vue 3)
  assets/js/scoring.js      Scoring rules: ties, DQs, places that move up
  assets/js/vue.global.prod.js, qrcode.js, icons.js   Bundled libraries, no CDN needed
  assets/fonts/             Barlow fonts
  nginx-example.conf        Example Nginx server block
  THIRD-PARTY-LICENSES.txt  Licenses for the bundled libraries and fonts
```
