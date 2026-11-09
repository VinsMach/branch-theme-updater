=== Branch Theme Updater ===
Contributors: mesciu
Tags: theme, updater, updates, branch
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Update any WordPress theme from a public or private GitHub branch, using the updates screen WordPress already has.

== Description ==

**Branch Theme Updater** connects one theme folder on your site to any GitHub repository. When the branch you chose has a new commit, WordPress offers the update in **Dashboard → Updates** and under **Appearance → Themes**, the same way it does for a theme from WordPress.org.

The plugin does not ship with a theme, and it does not point at a repository until you choose one. A new install is empty.

= What it is for =

* A theme you develop in Git and deploy by pushing a branch.
* A private theme repository that should update without FTP or a manual zip upload.
* A public GitHub theme that is not distributed through the WordPress.org theme directory.

= What you configure =

1. The GitHub URL, or the owner, repository name, and branch.
2. The theme folder name inside `wp-content/themes/`. You can pick an installed theme or type the slug of a theme that is not on the site yet.
3. A personal access token, only if the repository is private.

Public repositories work with no token. Save the settings once and WordPress takes it from there.

= How an update is detected =

The plugin does **not** require GitHub Releases, tags, or a changing version number in `style.css`.

On each update check it asks GitHub for the latest commit on the branch you selected and compares that commit with the commit recorded the last time this theme was installed. If they differ, WordPress is offered a package built from that branch. The version it shows keeps the version already written in the theme and adds the short commit id, for example `1.4.2+git.a1b2c3d`. That is enough for WordPress to treat the branch tip as a newer package even when the theme author did not bump `Version`.

After a successful update, the plugin stores the new commit id. The next check stays quiet until the branch moves again.

= What happens to the theme folder =

A GitHub zip is extracted into a folder named like `owner-repo-commit`. Before WordPress validates the package, the plugin renames that folder to the theme slug you configured, so the update replaces `wp-content/themes/your-theme` instead of leaving a second copy on disk.

The theme’s existing directory can be overwritten. Use a branch you are willing to deploy. The manual install tool asks for confirmation before it replaces the folder, and it moves the current folder aside until the new copy is in place.

= Public and private repositories =

* **Public.** Leave the token empty. GitHub’s API is enough to read the branch and download the zip.
* **Private.** Create a personal access token with read access to that repository’s contents. A fine-grained token limited to the one repository is the right scope. Classic tokens need the `repo` scope.

The token is stored in the WordPress database. After you save it, the settings page does not print it back into the form. Leave the field blank to keep the saved token, or tick **Remove saved token** to delete it. The token is sent only to GitHub, and only when the plugin reads the repository or downloads the theme.

= Tools on the settings page =

* **Clear cache** drops the cached commit id so the next check talks to GitHub again.
* **Show available branches** lists the branches your account can see and reports whether the repository is private.
* **Force install branch** downloads the selected branch and replaces the theme folder. Use it for the first install, or when you want the branch on disk without waiting for the update screen. It asks you to confirm first.

The native update screen only offers an update when that theme folder already exists. To put a theme on the site for the first time, use **Force install branch** or upload it once yourself, then let later commits arrive as normal updates.

= What this plugin does not do =

* It updates one theme per site, not every theme, and not plugins.
* It follows the tip of a branch. It does not present a list of GitHub Releases to roll back to.
* It does not edit `style.css` or keep a version number of its own inside the theme.
* It does not call the plugin author, and it does not send the site URL, user list, or content anywhere except to GitHub’s API for the repository you configured.
* It does not create the theme’s Git repository. You push the theme to GitHub yourself.

= Requirements =

* WordPress 5.8 or newer.
* PHP 7.4 or newer.
* The PHP `ZipArchive` extension, which is what WordPress uses to unpack theme packages.
* A theme directory WordPress can write to (`wp-content/themes`).
* For a private repository, a GitHub token that can read that repository.

== Installation ==

1. Upload the `branch-theme-updater` folder to `/wp-content/plugins/`, or install the zip from **Plugins → Add New → Upload Plugin**.
2. Activate **Branch Theme Updater**.
3. Open **Settings → Branch Theme Updater**.
4. Paste a repository URL such as `https://github.com/owner/repo` or `https://github.com/owner/repo/tree/main`. Owner, repository, and branch are filled from that URL when you save.
5. Set **Theme slug** to the folder name in `wp-content/themes/`.
6. For a private repository, add a personal access token and save again.
7. Use **Show available branches** to confirm the token can see the repository, then **Force install branch** if the theme is not already on the site.

Later updates appear on the WordPress updates screen.

== Frequently Asked Questions ==

= Does the plugin update a specific theme for me? =

No. Each site chooses its own theme folder and GitHub repository. Nothing is selected until you save the settings.

= Can I track more than one theme? =

One theme at a time. The settings page stores a single repository and a single theme slug.

= Can I use it for a plugin instead of a theme? =

No. It hooks the theme update system only.

= Do I need a token for a public repository? =

No. Add a token only when GitHub would refuse the request, which is the case for a private repository. A token also raises GitHub’s rate limit if a public repository is checked very often.

= Which token permissions are required? =

Read access to repository contents. Prefer a fine-grained personal access token that can read only the theme repository. You can create one at [github.com/settings/personal-access-tokens/new](https://github.com/settings/personal-access-tokens/new).

= Where is the token stored? =

In the `gtu_settings` option in the WordPress database. Administrators who can manage options can replace or remove it. The plugin does not put the saved token back into the settings form.

= Why does the offered version look like 1.2.0+git.abc1234? =

The plugin keeps the version written in `style.css` and appends the short commit id. WordPress can then see that the branch moved even when the theme’s own version number did not change.

= The theme version in style.css did not change. Will I still be notified? =

Yes. The comparison is the commit on the branch, not the version header.

= The update screen says the theme does not exist. =

The automatic update is offered only after the folder `wp-content/themes/{slug}` exists. Install the branch once with **Force install branch**, or upload the theme yourself. Check that the slug matches the directory name exactly.

= GitHub extracted a folder I do not recognize. =

That is the zipball name (`owner-repo` plus a commit id). The plugin renames it to your theme slug during install. If a rename is ever left behind, compare the folder with the slug on the settings page before deleting anything.

= Which branch is used? =

The branch saved in the settings. `main` is the default when the field is empty. **Show available branches** lists the names GitHub returned. A tag or commit id can be used in the branch field if you need a fixed revision instead of a moving branch tip.

= Does uninstall remove the token and the theme? =

Uninstalling the plugin deletes its settings, the stored commit ids, and its cached GitHub responses. It does not delete the theme, and it does not revoke the token on GitHub. Revoke a token you no longer want from the GitHub token settings.

= Does it work on Multisite? =

Settings are stored per site. Each site chooses its own repository. The plugin has not been written as a network-only admin screen.

== External services ==

This plugin connects to GitHub in order to do the job you configured.

It calls the GitHub API at `api.github.com` to read the repository, list branches, and read the latest commit on the selected branch. It downloads the theme zip from GitHub’s package host, `codeload.github.com`.

Each request includes the repository owner, name, and branch saved in the plugin settings. If you saved a personal access token, the plugin sends it to GitHub in the `Authorization` header. A normal browser User-Agent is sent as well, or the User-Agent you typed in the settings. The plugin does not send posts, users, comments, or other site content.

Requests happen when WordPress checks for updates, when you open the diagnostic tool, and when you force an install. The latest commit id is cached for 15 minutes.

GitHub privacy statement: [https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement](https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement)

== Changelog ==

= 1.1.0 =
* Start from an empty configuration so any theme and any GitHub repository can be used.
* Stop writing a built-in repository when the plugin is activated.
* Publish the plugin under the wordpress.org account mesciu.
* Keep a saved token out of the settings page HTML.
* Ask for confirmation before replacing a theme folder from the tools panel.
* Add the directory icon and banner.

= 1.0.7 =
* Update a single theme from a GitHub branch, including private repositories.
