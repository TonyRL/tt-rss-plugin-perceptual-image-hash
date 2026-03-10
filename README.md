## This plugin filters duplicate images using perceptual image hashing

* Hashing library: https://github.com/jenssegers/imagehash
* Requires PostgreSQL 14+ (uses native `bit_count()`)

### Installation

- Git clone to ``plugins.local/af_img_phash``
- Enable in feed editor for specific feeds (after enabling the plugin)
