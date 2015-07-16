<div class="wrap">
    <h2>Options</h2>
    <div class="mrp-admin options">
        <form name="mrp_options" method="POST" action="<?php echo esc_html(str_replace('%7E', '~', $_SERVER['REQUEST_URI'])) ?>">
            <input type="hidden" name="mrp_submit_hidden" value="Y"/>
            <p>
                Debug Mode
                <input type="checkbox" name="mrp_debug" value="1" <?php echo esc_html(($this->getOption("mrp_debug") ? "checked" : "")) ?>/>
            </p>
            <p>
                Google Map API Key
                <input type="text" name="mrp_google_api_key" value="<?php echo esc_html($this->getOption("mrp_google_api_key")) ?>" />
            </p>
            <p>
                <input type="submit" name="Save" value="Save"/>
            </p>
        </form>
    </div>

    <h2>Remote Configuration</h2>
    <div class="mrp-admin remote-config">
        <?php if (isset($this->config) && count($this->config)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Value</th>
                    </tr>
                </thead>
            <?php foreach ($this->config as $name => $value): ?>
                <tr>
                    <td><?php echo esc_html($name) ?></td>
                    <td><pre><?php echo esc_html(print_r($value, true)) ?></pre></td>
                </tr>
            <?php endforeach ?>
            </table>
        <?php else: ?>
            <p>No configuration to display.</p>
        <?php endif ?>
        <form name="mrp_config" method="POST" action="<?php echo (str_replace('%7E', '~', $_SERVER['REQUEST_URI'])) ?>">
            <input type="hidden" name="mrp_refresh_config" value="Y"/>
            <input type="submit" name="Refresh Config" value="Refresh Config" />
        </form>
    </div>

    <h2>Cache Settings</h2>
    <div class="mrp-admin cache-settings">
        <table>
            <?php foreach ($this->cache->getCacheUsage() as $name => $value): ?>
            <tr>
                <td><?php echo esc_html(ucwords(implode(' ', explode('_', $name)))) ?>: </td>
                <td><?php echo esc_html($value) ?></td>
            </tr>
            <?php endforeach ?>
        </table>

        <form name="mrp_clear_cache" method="POST" action="<?php echo (str_replace('%7E', '~', $_SERVER['REQUEST_URI'])) ?>">
            <input type="hidden" name="mrp_clear_cache" value="Y"/>
            <input type="submit" name="Clear Cache" value="Clear Cache" />
        </form>
    </div>

    <h2>Logging</h2>
    <div class="mrp-admin logging">
        <a href="<?php echo (str_replace('%7E', '~', $_SERVER['REQUEST_URI'])) ?>&mrp_get_logs=Y" target="_blank">
            Download Logs
        </a>
        <br/>
        <form name="mrp_clear_logs" method="POST" action="<?php echo (str_replace('%7E', '~', $_SERVER['REQUEST_URI'])) ?>">
            <input type="hidden" name="mrp_clear_logs" value="Y"/>
            <input type="submit" name="Clear Logs" value="Clear Logs" />
        </form>
    </div>
</div>