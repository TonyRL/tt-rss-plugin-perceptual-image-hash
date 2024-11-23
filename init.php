<?php
require_once __DIR__ . "/vendor/autoload.php";

use \Jenssegers\ImageHash\Implementations\PerceptualHash;
use \Jenssegers\ImageHash\ImageHash;

class Af_Img_Phash extends Plugin {

	/** @var PluginHost $host */
	private $host;

	/** @var int */
	private $default_similarity = 5;

	/** @var int (days) */
	private $data_max_age = 30;

	/** @var DiskCache $cache */
	private $cache;

	function about() {
		return array(null,
			"Filter duplicate images using perceptual hashing (requires GD)",
			"fox",
			false,
			"https://dev.tt-rss.org/fox/ttrss-perceptual-image-hash/wiki");
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/init.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/init.css");
	}

	function save() : void {
		$similarity = (int) $_POST["similarity"];

		$enable_globally = checkbox_to_sql_bool($_POST["phash_enable_globally"]);

		if ($similarity < 0) $similarity = 0;

		$this->host->set($this, "similarity", $similarity);
		$this->host->set($this, "enable_globally", $enable_globally);

		echo $this->__("Data saved.");
	}

	function init($host) {
		$this->host = $host;
		$this->cache = DiskCache::instance("images");

		Config::add("IMG_HASH_SQL_FUNCTION", "");

		$migrations = new Db_Migrations();
		$migrations->initialize_for_plugin($this);

		if ($migrations->migrate()) {
			$host->add_hook($host::HOOK_ARTICLE_FILTER, $this, 100);
			$host->add_hook($host::HOOK_PREFS_TAB, $this);
			$host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
			$host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
			$host->add_hook($host::HOOK_HOUSE_KEEPING, $this);
			$host->add_hook($host::HOOK_RENDER_ARTICLE, $this, 100);
			$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this, 100);
			$host->add_hook($host::HOOK_RENDER_ARTICLE_API, $this, 100);
			$host->add_hook($host::HOOK_ARTICLE_IMAGE, $this, 100);
		}
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		?>
		<div dojoType='dijit.layout.AccordionPane'
			title="<i class='material-icons'>photo</i> <?= $this->__( 'Filter similar images (af_img_phash)') ?>">

			<?php
			if (Config::get(Config::DB_TYPE) == "pgsql") {
				if (Config::get("IMG_HASH_SQL_FUNCTION")) {
					print_error("Using SQL implementation of bit_count; UI performance may not be as responsive as installing extension 'https://github.com/sldab/count-bits'. See README.txt");
				}
				else {
					try { $res = $this->pdo->query("select 'unique_1bits'::regproc"); } catch (PDOException $e) { ; }
					if (empty($res) || !$res->fetch()) {
						print_error("Required function from count_bits extension not found.");
					}
				}
			}

			$similarity = (int) $this->host->get($this, "similarity", $this->default_similarity);
			$enable_globally = $this->host->get($this, "enable_globally");

			?>
			<form dojoType='dijit.form.Form'>

				<?= \Controls\pluginhandler_tags($this, "save") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('Saving data...', true);
						xhrPost("backend.php", this.getValues(), (transport) => {
							Notify.info(transport.responseText);
						})
					}
				</script>

				<fieldset>
					<label><?= $this->__( "Maximum Hamming distance:") ?></label>
					<input dojoType='dijit.form.NumberSpinner'
						placeholder='5' required='1' name='similarity' id='phash_img_similarity' value='<?= $similarity ?>'>

					<div dojoType='dijit.Tooltip' connectId='phash_img_similarity' position='below'>
					<?= $this->__( "Lower Hamming distance value indicates images being more similar.") ?>
					</div>
				</fieldset>

				<fieldset class='narrow'>
					<label class='checkbox'>
						<?= \Controls\checkbox_tag("phash_enable_globally", $enable_globally) ?>
						<?= $this->__( "Enable for all feeds") ?>
					</label>
				</fieldset>

				<hr/>
				<?= \Controls\submit_tag($this->__( "Save")) ?>

			</form>

			<?php
			$enabled_feeds = $this->filter_unknown_feeds(
				$this->host->get_array($this, "enabled_feeds"));

			$this->host->set($this, "enabled_feeds", $enabled_feeds);

			if (count($enabled_feeds) > 0) { ?>
				<hr/>
				<h3><?= __("Currently enabled for (click to edit):") ?></h3>

				<ul class='panel panel-scrollable list list-unstyled'>
				<?php foreach ($enabled_feeds as $f) { ?>
					<li><i class='material-icons'>rss_feed</i> <a href='#' onclick="CommonDialogs.editFeed(<?= $f ?>)">
						<?= htmlspecialchars(Feeds::_get_title($f)) ?></a></li>
				<?php } ?>
				</ul>
			<?php	} ?>
		</div>
		<?php
	}

	function hook_prefs_edit_feed($feed_id) {
		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
		?>
		<header><?= $this->__( "Similar images") ?></header>
		<section>
			<fieldset>
				<label class='checkbox'>
					<?= \Controls\checkbox_tag("phash_similarity_enabled", in_array($feed_id, $enabled_feeds)) ?>
					<?= $this->__( 'Filter similar images') ?></label>
			</fieldset>
		</section>
		<?php
	}

	function hook_prefs_save_feed($feed_id) {
		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");

		$enable = checkbox_to_sql_bool($_POST["phash_similarity_enabled"] ?? "");
		$key = array_search($feed_id, $enabled_feeds);

		if ($enable) {
			if ($key === false) {
				array_push($enabled_feeds, $feed_id);
			}
		} else {
			if ($key !== false) {
				unset($enabled_feeds[$key]);
			}
		}

		$this->host->set($this, "enabled_feeds", $enabled_feeds);
	}

	private function rewrite_duplicate(DOMDocument $doc, DOMElement $elem, bool $api_mode = false) : void {

		if ($elem->hasAttribute("src")) {
			$uri = UrlHelper::validate($elem->getAttribute("src"));
			$check_uri = $uri;
		} else if ($elem->hasAttribute("poster")) {
			$check_uri = UrlHelper::validate($elem->getAttribute("poster"));

			/** @var DOMElement|false */
			$video_source = $elem->getElementsByTagName("source")->item(0);

			if ($video_source) {
				$uri = $video_source->getAttribute("src");
			}
		}

		if (!empty($check_uri) && !empty($uri)) {

			if ($api_mode) {
				$a = $doc->createElement("a");
				$a->appendChild(new DOMText(truncate_middle($uri, 48, "...")));
				$a->setAttribute("href", $uri);
				$a->setAttribute("target", "_blank");
				$a->setAttribute("rel", "noopener noreferrer");

				$elem->parentNode->replaceChild($a, $elem);
			} else {
				$det = $doc->createElement("details");
				$sum = $doc->createElement("summary");

				$a = $doc->createElement("a");
				$a->appendChild(new DOMText(truncate_middle($uri, 48, "...")));
				$a->setAttribute("href", $uri);
				$a->setAttribute("target", "_blank");
				$a->setAttribute("rel", "noopener noreferrer");

				$sum->appendChild($a);
				$det->appendChild($sum);

				$a = $doc->createElement("a");
				$a->setAttribute("href", "#");
				$a->setAttribute("onclick", "Plugins.Af_Img_Phash.showSimilar(this)");
				$a->setAttribute("data-check-url", UrlHelper::validate($check_uri));
				$a->appendChild(new DOMText("(similar)"));

				$sum->appendChild(new DOMText(" "));
				$sum->appendChild($a);

				$elem->parentNode->replaceChild($det, $elem);
				$det->appendChild($elem);
			}
		}
	}

	function hook_article_filter($article) {

		$enable_globally = $this->host->get($this, "enable_globally");

		if (!$enable_globally) {
			if (!in_array($article["feed"]["id"],
					$this->host->get_array($this, "enabled_feeds"))) {

				return $article;
			}
		}

		$owner_uid = $article["owner_uid"];
		$article_guid = $article["guid_hashed"];

		$doc = new DOMDocument();

		if (!empty($article["content"]) && @$doc->loadHTML($article["content"])) {
			$xpath = new DOMXPath($doc);

			$imgs = $xpath->query("//img[@src]|//video[@poster]");

			foreach ($imgs as $img) {

				$src = $img->tagName == "video" ? $img->getAttribute("poster") : $img->getAttribute("src");
				$src = UrlHelper::validate(UrlHelper::rewrite_relative($article["link"], $src));

				Debug::log("phash: checking $src", Debug::LOG_VERBOSE);

				$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_img_phash_urls WHERE
					owner_uid = ? AND url = ? LIMIT 1");
				$sth->execute([$owner_uid, $src]);

				if ($sth->fetch()) {
					Debug::log("phash: url already stored, not processing", Debug::LOG_VERBOSE);
					continue;
				} else {
					Debug::log("phash: probing content type...", Debug::LOG_VERBOSE);

					$content_type = $this->get_content_type($src);

					Debug::log("phash: content type: $content_type", Debug::LOG_VERBOSE);

					if (strpos($content_type, "image/") === FALSE) {
						Debug::log("phash: received content type is not an image, marking as processed and skipping.", Debug::LOG_VERBOSE);

						$sth = $this->pdo->prepare("INSERT INTO
									ttrss_plugin_img_phash_urls (url, article_guid, owner_uid, phash) VALUES
									(?, ?, ?, ?)");
						$sth->execute([$src, $article_guid, $owner_uid, '-1']);
						continue;
					}

					$cached_file = sha1($src);
					$cached_file_flag = "$cached_file.phash-flag";

					if ($this->cache->is_writable()) {

						// check for .flag to prevent repeated failures or create it
						if ($this->cache->exists($cached_file_flag)) {
							Debug::log("phash: $cached_file_flag exists, looks like we failed on this URL before; skipping.", Debug::LOG_VERBOSE);
							continue;
						} else {
							$this->cache->put($cached_file_flag, "");
						}

						// check for local cache
						if (!$this->cache->exists($cached_file)) {
							Debug::log("phash: downloading URL...", Debug::LOG_VERBOSE);

							$data = UrlHelper::fetch(["url" => $src, "max_size" => Config::get(Config::MAX_CACHE_FILE_SIZE)]);

							if ($data) {
								$this->cache->put($cached_file, $data);
							}
						}

						if ($this->cache->exists($cached_file)) {
							Debug::log("phash: using local cache...", Debug::LOG_VERBOSE);

							$implementation = new PerceptualHash();
							$hasher = new ImageHash($implementation);

							$hash = (string)$hasher->hash($this->cache->get_full_path($cached_file));

							Debug::log("phash: calculated perceptual hash: $hash", Debug::LOG_VERBOSE);

							// we managed to process this image, it should be safe to remove the flag now
							$this->cache->remove($cached_file_flag);

							if ($hash) {
								$hash = base_convert($hash, 16, 10);

								if (PHP_INT_SIZE > 4) {
									while ($hash > PHP_INT_MAX) {
										$bitstring = base_convert($hash, 10, 2);
										$bitstring = substr($bitstring, 1);

										$hash = base_convert($bitstring, 2, 10);
									}
								}

								$sth = $this->pdo->prepare("INSERT INTO
									ttrss_plugin_img_phash_urls (url, article_guid, owner_uid, phash) VALUES
									(?, ?, ?, ?)");
								$sth->execute([$src, $article_guid, $owner_uid, $hash]);
							}
						}

					} else {
						Debug::log("phash: cache directory is not writable", Debug::LOG_VERBOSE);
						return $article;
					}
				}
			}
		}

		return $article;
	}

	function api_version() {
		return 2;
	}

	/**
	 * @param array<int> $enabled_feeds
	 * @return array<int>
	 * @throws PDOException
	 */
	private function filter_unknown_feeds(array $enabled_feeds) : array {
		$tmp = array();

		foreach ($enabled_feeds as $feed) {

			$sth = $this->pdo->prepare("SELECT id FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
			$sth->execute([$feed, $_SESSION['uid']]);

			if ($row = $sth->fetch()) {
				array_push($tmp, $feed);
			}
		}

		return $tmp;
	}

	function hook_render_article($article) {
		return $this->_hook_render_article_cdm($article, false);
	}

	function hook_render_article_api($row) {
		$article = isset($row['headline']) ? $row['headline'] : $row['article'];

		return $this->_hook_render_article_cdm($article, true);
	}

	function hook_article_image($enclosures, $content, $site_url, $article) {
		// fake guid because of further checking in hook_render_article_cdm() which we don't need here
		$article = $this->_hook_render_article_cdm(["guid" => time(), "content" => $content], true);

		return ["", "", $article["content"]];
	}

	/** we can't freely screw around with hook argument lists anymore
	 * @param array<string,mixed> $article
	 * @param bool $api_mode
	 * @return array<string,mixed>
	 * @throws PDOException
	 */
	private function _hook_render_article_cdm($article, $api_mode) {
		/* if (Config::get(Config::DB_TYPE) == "pgsql" && !Config::get("IMG_HASH_SQL_FUNCTION")) {
			try { $res = $this->pdo->query("select 'unique_1bits'::regproc"); } catch (PDOException $e) { ; }
			if (empty($res) || !$res->fetch()) return $article;
		} */

		$owner_uid = $_SESSION["uid"];
		$similarity = (int) $this->host->get($this, "similarity", $this->default_similarity);

		$doc = new DOMDocument();
		$article_guid = ($article["guid"] ?? false);
		$need_saving = false;

		if (!empty($article_guid) && !empty($article["content"]) && @$doc->loadHTML($article["content"])) {
			$xpath = new DOMXPath($doc);
			$imgs = $xpath->query("//img[@src]|//video[@poster]");

			foreach ($imgs as $img) {

				$src = $img->tagName == "video" ? $img->getAttribute("poster") : $img->getAttribute("src");
				$src = UrlHelper::validate(UrlHelper::rewrite_relative($article["link"] ?? "", $src));

				// check for URL duplicates first

				$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_img_phash_urls WHERE
						owner_uid = ? AND
						url = ? AND
						article_guid != ? LIMIT 1");
				$sth->execute([$owner_uid, $src, $article_guid]);

				if ($sth->fetch()) {
					$need_saving = true;
					$this->rewrite_duplicate($doc, $img, $api_mode);
					continue;
				}

				// check using perceptual hash duplicates

				$sth = $this->pdo->prepare("SELECT phash FROM ttrss_plugin_img_phash_urls WHERE
					owner_uid = ? AND
					phash != -1 AND
					url = ? LIMIT 1");
				$sth->execute([$owner_uid, $src]);

				if ($row = $sth->fetch()) {
					$phash = $row['phash'];

					//$similarity = 15;

					$sth = $this->pdo->prepare("SELECT article_guid FROM ttrss_plugin_img_phash_urls WHERE
						owner_uid = ? AND
						created_at >= ".$this->interval_days($this->data_max_age)." AND
						".$this->bitcount_func($phash)." <= ? ORDER BY created_at LIMIT 1");
					$sth->execute([$owner_uid, $similarity]);

					if ($row = $sth->fetch()) {

						$test_guid = $row['article_guid'];

						if ($test_guid != $article_guid) {
							$need_saving = true;
							$this->rewrite_duplicate($doc, $img, $api_mode);
						}
					}
				}
			}
		}

		if ($need_saving) $article["content"] = $doc->saveXML();

		return $article;
	}

	function hook_render_article_cdm($article) {
		return $this->_hook_render_article_cdm($article, false);
	}

	function hook_house_keeping() {
		$this->pdo->query("DELETE FROM ttrss_plugin_img_phash_urls
			WHERE created_at < ".$this->interval_days($this->data_max_age));
	}

	private function guid_to_article_title(string $article_guid, int $owner_uid) : string {
		$sth = $this->pdo->prepare("SELECT feed_id, title, updated
			FROM ttrss_entries, ttrss_user_entries
			WHERE ref_id = id AND
				guid = ? AND
				owner_uid = ?");
		$sth->execute([$article_guid, $owner_uid]);

		if ($row = $sth->fetch()) {
			$article_title = htmlspecialchars($row["title"]);
			$feed_id = $row["feed_id"];
			$updated = $row["updated"];

			$article_title = $this->T_sprintf("%s (%s) %s",
				"<span title='$article_guid'>$article_title</span>",
				TimeHelper::make_local_datetime($updated, true),
				"<a href='#' onclick='Feeds.open({feed: $feed_id})'><i class='material-icons'>rss_feed</i></a>");

		} else {
			$article_title = "N/A ($article_guid)";
		}

		return $article_title;
	}

	function showsimilar() : void {
		$url = $_REQUEST["url"];
		$owner_uid = $_SESSION["uid"];
		$similarity = (int) $this->host->get($this, "similarity", $this->default_similarity);

		?>
		<section class='narrow'>
			<img class='trgm-related-thumb pull-right' src="<?= htmlspecialchars($url) ?>">

			<fieldset>
					<label><?= $this->__("URL:") ?></label>
					<a target='_blank' href="<?= htmlspecialchars($url) ?>">
						<?= truncate_middle(htmlspecialchars($url), 48) ?>
					</a>
			</fieldset>
		<?php

		$sth = $this->pdo->prepare("SELECT phash FROM ttrss_plugin_img_phash_urls WHERE
			owner_uid = ? AND
			phash != -1 AND
			url = ? LIMIT 1");
		$sth->execute([$owner_uid, $url]);

		if ($row = $sth->fetch()) {

			$phash = $row['phash'];

			$sth = $this->pdo->prepare("SELECT article_guid, ".SUBSTRING_FOR_DATE."(created_at,1,19) AS created_at FROM ttrss_plugin_img_phash_urls WHERE
							owner_uid = ? AND
							created_at >= ".$this->interval_days($this->data_max_age)." AND
							".$this->bitcount_func($phash)." <= ? ORDER BY created_at LIMIT 1");
			$sth->execute([$owner_uid, $similarity]);

			if ($row = $sth->fetch()) {

				$article_guid = $row['article_guid'];
				$article_title = $this->guid_to_article_title($article_guid, $owner_uid);
				$created_at = $row['created_at'];

				?>
				<fieldset class='narrow'><label><?= $this->__( "Perceptual hash:") ?></label>
					<?= base_convert($phash, 10, 16) ?>
				</fieldset>
				<fieldset class='narrow'><label><?= $this->__( "Belongs to:") ?></label>
					<?= $article_title ?>
				</fieldset>
				<fieldset class='narrow'><label><?= $this->__( "Registered:") ?></label>
					<?= $created_at ?>
				</fieldset>

				<ul class='panel panel-scrollable list list-unstyled'>
					<?php

					$sth = $this->pdo->prepare("SELECT url, article_guid, ".$this->bitcount_func($phash)." AS distance
						FROM ttrss_plugin_img_phash_urls WHERE
						".$this->bitcount_func($phash)." <= ?
						ORDER BY distance LIMIT 30");
					$sth->execute([$similarity]);

					while ($line = $sth->fetch()) {
						$url = htmlspecialchars($line["url"]);
						$distance = $line["distance"];
						$rel_article_guid = $line["article_guid"];
						$article_title = $this->guid_to_article_title($rel_article_guid, $owner_uid);

						$is_checked = ($rel_article_guid == $article_guid) ? "checked" : "";

						?>
						<li>
							<div>
								<a target='_blank' href="<?= $url ?>"><?= truncate_middle($url, 48) ?></a>

								(<?= $this->T_sprintf("Distance: %d", $distance) ?>)

								<?php if ($is_checked) { ?>
									<strong>(<?= $this->__( "Original") ?>)</strong>
								<?php } ?>

								<div><?= $article_title ?></div>
								<div><img class='trgm-related-thumb' src="<?= $url ?>"></div>
							</div>
						</li>
						<?php
					}
					?>
				</ul>
				<?php

			} else {
				print "<div class='text-error'>" . $this->__( "No information found for this URL.") . "</div>";
			}
		} else {
			print "<div class='text-error'>" . $this->__( "No information found for this URL.") . "</div>";
		}

		?>
		</section>

		<footer class='text-center'>
			<button dojoType='dijit.form.Button' class='alt-primary' type='submit'>
				<?= $this->__( 'Close this window') ?>
			</button>
		</footer>

		<?php
	}

	private function interval_days(int $days) : string {
		if (Config::get(Config::DB_TYPE) == "pgsql") {
			return "NOW() - INTERVAL '$days days' ";
		} else {
			return "DATE_SUB(NOW(), INTERVAL $days DAY) ";
		}
	}

	private function bitcount_func(string $phash) : string {
		if (Config::get(Config::DB_TYPE) == "pgsql") {
			return Config::get("IMG_HASH_SQL_FUNCTION") ? "bit_count('$phash' # phash)" : "unique_1bits('$phash', phash)";
		} else {
			return "bit_count('$phash' ^ phash)";
		}
	}

	/** $useragent defaults to Config::get_user_agent() */
	private function get_header(string $url, int $header, string $useragent = "") : string {
		$ret = "";

		if (function_exists("curl_init")) {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HEADER, true);
			curl_setopt($ch, CURLOPT_NOBODY, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, !ini_get("open_basedir"));
			curl_setopt($ch, CURLOPT_USERAGENT, $useragent ? $useragent : Config::get_user_agent());

			@curl_exec($ch);
			$ret = curl_getinfo($ch, $header);
		}

		return $ret;
	}

	private function get_content_type(string $url, string $useragent = "") : string {
		return $this->get_header($url, CURLINFO_CONTENT_TYPE, $useragent);
	}

}
