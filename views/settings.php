<h1>Settings</h1>

<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row">
				Google Map Key
			</th>
			<td>
				<form name="mrp_options" method="POST" action="<?php echo esc_html(str_replace('%7E', '~', $_SERVER['REQUEST_URI'])) ?>">
					<input type="hidden" name="mrp_submit_hidden" value="Y"/>
					<input type="text" name="mrp_google_api_key" value="<?php echo esc_html($this->getOption("mrp_google_api_key")) ?>" />
					<input type="hidden" name="mrp_debug" value=""/>
					<input type="submit" name="Save" value="Save" class="button button-primary"/>
				</form>
			</td>
		</tr>
	</tbody>
</table>

<h1>Remote Settings</h1>
<table class="form-table" role="presentation">
		<?php foreach ($this->config as $name => $value): ?>
			<tr>
				<th scope="row">
					<?php if(esc_html($name) == "version") { ?>
						Version
					<?php } elseif(esc_html($name) == "managed_urls") { ?>
						Managed URLS
					<?php } elseif(esc_html($name) == "replaceable_titles") { ?>
						Replaceable Titles
					<?php } else { ?>
						<?php echo esc_html($name) ?><br>
					<?php } ?>
				</th>
				<td>
					<fieldset>
						<?php if(esc_html($name) == "managed_urls") { ?>
							<textarea readonly style="width:100%;height:200px;"><?php echo esc_html(print_r($value, true)) ?></textarea><br>
						<?php } elseif(esc_html($name) == "replaceable_titles") { ?>
							<textarea readonly style="width:100%;"><?php echo esc_html(print_r($value, true)) ?></textarea><br>
						<?php } else { ?>
							<?php echo esc_html(print_r($value, true)) ?>
						<?php } ?>
					</fieldset>
				</td>
			</tr>
		<?php endforeach ?>
		<tr>
			<th scope="row">
			</th>
			<td>
				<form name="mrp_config" method="POST" action="<?php echo (str_replace('%7E', '~', $_SERVER['REQUEST_URI'])) ?>">
					<input type="hidden" name="mrp_refresh_config" value="Y"/>
					<input type="submit" name="Refresh Config" value="Refresh Config" class="button" class="button"/>
				</form>
			</td>
		</tr>
	</tbody>
</table>

<h1>Cache Settings</h1>
<table class="form-table" role="presentation">
	<tbody>
		<?php foreach ($this->cache->getCacheUsage() as $name => $value): ?>
			<tr>
				<th scope="row">
					<?php echo esc_html(ucwords(implode(' ', explode('_', $name)))) ?>
				</th>
				<td>
					<?php echo esc_html($value) ?>
				</td>
			</tr>
		<?php endforeach ?>
		<tr>
			<th scope="row">
			</th>
			<td>
				<form name="mrp_clear_cache" method="POST" action="<?php echo (str_replace('%7E', '~', $_SERVER['REQUEST_URI'])) ?>">
					<input type="hidden" name="mrp_clear_cache" value="Y"/>
					<input type="submit" name="Clear Cache" value="Clear Cache" class="button"/>
				</form>
			</td>
		</tr>
	</tbody>
</table>

<h1>Logging Settings</h1>
<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row">
				Logs
			</th>
			<td>
				<a href="<?php echo (str_replace('%7E', '~', $_SERVER['REQUEST_URI'])) ?>&mrp_get_logs=Y" target="_blank">
					Download Logs
					<br/><br/>
					<form name="mrp_clear_logs" method="POST" action="<?php echo (str_replace('%7E', '~', $_SERVER['REQUEST_URI'])) ?>">
						<input type="hidden" name="mrp_clear_logs" value="Y"/>
						<input type="submit" name="Clear Logs" value="Clear Logs" class="button" />
					</form>
				</a>
			</td>
		</tr>
	</tbody>
</table>
