# Adding Your Parish Logo

Drop your logo image into this folder as:

    logo.png

That's it — every crest across the site (dashboard sidebar on every role,
homepage, About page, login/register pages, and the installer) will pick
it up automatically. No code changes needed.

## Recommended image
- **File name:** exactly `logo.png` (lowercase)
- **Shape:** square works best — it's displayed inside a circle, so the
  center of your image is what shows (corners get cropped off)
- **Size:** at least 200×200px, kept under ~300KB so pages load fast
- **Background:** transparent or a background that matches the gold/brown
  theme looks most seamless, but any background works fine

## Removing it
Delete or rename `logo.png` and every page falls back to the default
gold "P" text crest automatically — nothing else needs to change.

## Adding other images (e.g. a photo on the About page)
Any image you drop in this `public/img/` folder can be referenced from
any `.php` file with:

    <img src="<?= url('public/img/your-file.jpg') ?>" alt="Description">
