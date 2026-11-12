# Legal pages

The imprint and the privacy notice belong to whoever runs an install, so the
application ships none. To publish them, copy the two example files, fill in
your details, and put the results where `EXPOSEHUB_LEGAL_PATH` points
(default: `storage/app/private/legal`):

    imprint.md
    privacy.md

The pages `/imprint` and `/privacy` and the links to them appear as soon as the
files exist. The files are Markdown; raw HTML in them is dropped.

`privacy.example.md` describes what this software stores and why, as of the
version it ships with. Parts in [square brackets] depend on your install. Have
the result checked by someone qualified before you publish it: the examples
are a starting point, not legal advice.
