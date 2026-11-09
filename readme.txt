=== Branch Theme/Plugin Updater ===
Contributors: mesciu
Tags: theme, plugin, updater, updates, branch
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Update one theme or one plugin from a GitHub branch. WordPress offers it on the updates screen you already use.

== Description ==

**Branch Theme/Plugin Updater** follows one GitHub branch and installs it as a theme or as a plugin. You choose which. When that branch gets a new commit, WordPress offers the update in **Dashboard → Updates**, the same way it offers a package from WordPress.org.

A new install is empty. Nothing is selected until you save a repository.

= A theme, or a plugin =

**Theme.** Set the folder name inside `wp-content/themes/`. You can pick a theme that is already installed, or type the slug of one that is not on the site yet. Updates also appear under **Appearance → Themes**.

**Plugin.** Set the main file as `folder/file.php` inside `wp-content/plugins/`. That file needs a Plugin Name header, and it must sit in the repository root or in a folder directly under the root. Updates also appear under **Plugins**. An active plugin stays active when the folder and the main file keep the same names.

One site tracks one package. A site already set to a theme keeps that theme until you change the setting and save.

= Put it on the site once =

**Dashboard → Updates** lists a theme or a plugin only after it is already installed.

1. Choose **Theme** or **Plugin**.
2. Paste a repository URL such as `https://github.com/owner/repo` or `https://github.com/owner/repo/tree/main`, then save. Owner, repository, and branch are filled from that URL.
3. Use **Show available branches** to confirm GitHub returned the branch.
4. Use **Force install branch** if the package is not on the site yet. It asks before it replaces the folder, and it moves the current folder aside until the new copy is in place.

Later commits arrive as normal updates. **Force install branch** will not replace Branch Theme/Plugin Updater itself. Update this plugin from **Dashboard → Updates**.

= How a new commit becomes an update =

No GitHub Release, no tag, and no version bump is required.

On each check the plugin reads the latest commit on your branch and compares it with the commit stored at the last install. If they differ, WordPress is offered a zip of that branch. The version on the updates screen keeps the version in `style.css` or the plugin header and adds the short commit id, for example `1.4.2+git.a1b2c3d`.

After a successful update the plugin stores the new commit id. The next check stays quiet until the branch moves again.

GitHub names the extracted folder `owner-repo` plus a commit id. Before WordPress validates the package, this plugin renames that folder to your theme slug or plugin folder, so the update replaces the directory you configured.

= Public and private repositories =

* **Public.** Leave the token empty.
* **Private.** Create a personal access token that can read the repository contents. A fine-grained token limited to that one repository is enough. Classic tokens need the `repo` scope.

The token is stored in the WordPress database. After you save it, the settings page does not print it back into the form. Leave the field blank to keep the saved token, or tick **Remove saved token** to delete it. The token is sent only to GitHub, and only when the plugin reads the repository or downloads the zip.

= On the settings page =

The page opens with the two choices, **Theme** and **Plugin**. The form then shows the folder field for a theme, or the `folder/file.php` field for a plugin.

* **Clear cache** drops the cached commit id so the next check talks to GitHub again. The commit id is cached for 15 minutes.
* **Show available branches** lists the branch names GitHub returned and reports whether the repository is private.
* **Force install branch** downloads the selected branch and replaces the theme folder or the plugin folder.

= What this plugin does not do =

* It does not update every theme and every plugin. One package per site.
* It follows the tip of a branch. It does not list GitHub Releases you can roll back to.
* It does not edit `style.css` or a plugin header.
* It does not send posts, users, comments, or the site URL anywhere except to GitHub, for the repository you configured.
* It does not create the Git repository. You push the theme or plugin yourself.

= Requirements =

* WordPress 5.8 or newer.
* PHP 7.4 or newer.
* The PHP `ZipArchive` extension, which is what WordPress uses to unpack packages.
* A writable `wp-content/themes` or `wp-content/plugins` directory.
* For a private repository, a GitHub token that can read that repository.

== Installation ==

1. Upload the `branch-theme-updater` folder to `/wp-content/plugins/`, or install the zip from **Plugins → Add New → Upload Plugin**.
2. Activate **Branch Theme/Plugin Updater**.
3. Open **Settings → Branch Theme/Plugin Updater**.
4. Choose **Theme** or **Plugin**.
5. Paste a repository URL such as `https://github.com/owner/repo` or `https://github.com/owner/repo/tree/main`.
6. For a theme, set **Theme slug** to the folder in `wp-content/themes/`. For a plugin, set **Plugin file** to `folder/file.php`.
7. For a private repository, add a personal access token and save again.
8. Use **Show available branches**, then **Force install branch** if the theme or plugin is not already on the site.

Later updates appear on the WordPress updates screen.

== Frequently Asked Questions ==

= Can it update a plugin? =

Yes. Choose **Plugin** and enter the main file as `folder/file.php`. The file needs a Plugin Name header and must be in the repository root, or in a folder directly inside it. Install the branch once with **Force install branch**. Later commits show up on **Dashboard → Updates** and under **Plugins**. The button will not overwrite Branch Theme/Plugin Updater itself.

= Can it update a theme? =

Yes. Choose **Theme** and enter the folder name in `wp-content/themes/`. Install the branch once, then later commits show up on **Dashboard → Updates** and under **Appearance → Themes**. A site that was already configured for a theme stays on that theme.

= Can I track a theme and a plugin at the same time? =

No. The settings store one repository and either one theme slug or one plugin file. Change the choice when you want the other.

= Do I need a token for a public repository? =

No. Add a token only when GitHub would refuse the request, which is the case for a private repository. A token also raises GitHub’s rate limit if a public repository is checked very often.

= Which token permissions are required? =

Read access to repository contents. Prefer a fine-grained personal access token that can read only that repository. You can create one at [github.com/settings/personal-access-tokens/new](https://github.com/settings/personal-access-tokens/new).

= Where is the token stored? =

In the `gtu_settings` option in the WordPress database. Administrators who can manage options can replace or remove it. The plugin does not put the saved token back into the settings form.

= Why does the offered version look like 1.2.0+git.abc1234? =

The plugin keeps the version written in `style.css` or the plugin header and appends the short commit id. WordPress can then see that the branch moved even when that version number did not change.

= The version header did not change. Will I still be notified? =

Yes. The comparison is the commit on the branch, not the version header.

= The update screen does not list my theme or plugin. =

The automatic update is offered only after the theme folder or the plugin is already installed. Install the branch once with **Force install branch**, or upload the package yourself. The slug, or `folder/file.php`, has to match the directory name exactly.

= GitHub extracted a folder I do not recognize. =

That is the zipball name (`owner-repo` plus a commit id). The plugin renames it to your theme slug or plugin folder during install. If a rename is ever left behind, compare the folder with the settings page before deleting anything.

= Which branch is used? =

The branch saved in the settings. `main` is the default when the field is empty. **Show available branches** lists the names GitHub returned. A tag or a commit id can be used in the branch field when you want a fixed revision instead of the moving branch tip.

= Does uninstall remove the token and the package I was updating? =

Uninstalling this plugin deletes its settings, the stored commit ids, and its cached GitHub responses. It does not delete the theme or plugin you were updating, and it does not revoke the token on GitHub. Revoke a token you no longer want from the GitHub token settings.

= Does it work on Multisite? =

Settings are stored per site. Each site chooses its own repository and its own theme or plugin. The plugin has not been written as a network-only admin screen.

== External services ==

This plugin connects to GitHub in order to do the job you configured.

It calls the GitHub API at `api.github.com` to read the repository, list branches, and read the latest commit on the selected branch. It downloads the zip from GitHub’s package host, `codeload.github.com`.

Each request includes the repository owner, name, and branch saved in the plugin settings. If you saved a personal access token, the plugin sends it to GitHub in the `Authorization` header. A normal browser User-Agent is sent as well, or the User-Agent you typed in the settings. The plugin does not send posts, users, comments, or other site content.

Requests happen when WordPress checks for updates, when you open the diagnostic tool, and when you force an install. The latest commit id is cached for 15 minutes.

GitHub privacy statement: [https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement](https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement)

== Changelog ==

= 1.2.0 =
* Update one plugin from a branch, using the same commit check already used for themes.
* Choose theme or plugin on the settings page. The form shows the field that matches that choice. Existing setups stay on the theme.
* Install a plugin for the first time with Force install branch. Later commits use the WordPress updates screen.

= 1.1.0 =
* Start from an empty configuration so any theme and any GitHub repository can be used.
* Stop writing a built-in repository when the plugin is activated.
* Publish the plugin under the wordpress.org account mesciu.
* Keep a saved token out of the settings page HTML.
* Ask for confirmation before replacing a theme folder from the tools panel.
* Add the directory icon and banner.

= 1.0.7 =
* Update a single theme from a GitHub branch, including private repositories.
