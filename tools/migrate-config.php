<?php
/*
 * Legacy Kangle XML configuration migrator.
 *
 * This file is invoked by migrate-config.sh.  It intentionally supports
 * PHP 5.6 syntax because older Kangle installations commonly ship PHP 5.x.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This program must be run from the command line.\n");
    exit(1);
}

function usage($exitCode)
{
    $text = <<<'HELP'
Usage:
  migrate-config.sh [options]

Default operation:
  - converts /vhs/kangle/etc/config.xml in place;
  - converts /home/ftp/\*/\*/access.xml (site-root files only);
  - creates a timestamped backup beside every changed file.

Options:
  --kangle-dir DIR      Kangle installation directory (default: /vhs/kangle)
  --config FILE         Main config.xml path
  --template FILE       New config-default.xml used to fill defaults
  --ftp-root DIR        Parent of the two-level site directories (default: /home/ftp)
  --access-file FILE    Convert an additional access.xml; may be repeated
  --no-main             Do not convert the main config.xml
  --no-sites            Do not scan --ftp-root
  --dry-run             Parse and report changes without writing files
  --output-dir DIR      Write converted copies below DIR instead of replacing
  --backup-suffix TEXT  Backup suffix (default: .bak.YYYYmmdd-HHMMSS)
  --reload              Run "bin/kangle -r" after a successful in-place update
  -h, --help            Show this help

Examples:
  # Preview the real migration
  ./migrate-config.sh --dry-run

  # Convert the demo files into /tmp/kangle-converted without changing them
  ./migrate-config.sh --config /path/demo/config.xml --no-sites \
      --access-file /path/demo/access.xml --output-dir /tmp/kangle-converted

  # Convert the real files and gracefully reload Kangle
  ./migrate-config.sh --reload
HELP;
    fwrite($exitCode === 0 ? STDOUT : STDERR, $text . "\n");
    exit($exitCode);
}

function parseArguments($argv)
{
    $options = array(
        'kangle_dir' => '/vhs/kangle',
        'config' => null,
        'template' => null,
        'ftp_root' => '/home/ftp',
        'access_files' => array(),
        'main' => true,
        'sites' => true,
        'dry_run' => false,
        'output_dir' => null,
        'backup_suffix' => '.bak.' . date('Ymd-His'),
        'reload' => false,
    );

    $valueOptions = array(
        '--kangle-dir' => 'kangle_dir',
        '--config' => 'config',
        '--template' => 'template',
        '--ftp-root' => 'ftp_root',
        '--output-dir' => 'output_dir',
        '--backup-suffix' => 'backup_suffix',
    );

    for ($i = 1; $i < count($argv); ++$i) {
        $arg = $argv[$i];
        if ($arg === '-h' || $arg === '--help') {
            usage(0);
        }
        if ($arg === '--no-main') {
            $options['main'] = false;
            continue;
        }
        if ($arg === '--no-sites') {
            $options['sites'] = false;
            continue;
        }
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }
        if ($arg === '--reload') {
            $options['reload'] = true;
            continue;
        }
        if ($arg === '--access-file' || strpos($arg, '--access-file=') === 0) {
            if ($arg === '--access-file') {
                if (!isset($argv[++$i])) {
                    fwrite(STDERR, "error: --access-file requires a value\n");
                    usage(2);
                }
                $value = $argv[$i];
            } else {
                $value = substr($arg, strlen('--access-file='));
            }
            $options['access_files'][] = $value;
            continue;
        }

        $matched = false;
        foreach ($valueOptions as $longOption => $key) {
            if ($arg === $longOption) {
                if (!isset($argv[++$i])) {
                    fwrite(STDERR, "error: {$longOption} requires a value\n");
                    usage(2);
                }
                $options[$key] = $argv[$i];
                $matched = true;
                break;
            }
            if (strpos($arg, $longOption . '=') === 0) {
                $options[$key] = substr($arg, strlen($longOption) + 1);
                $matched = true;
                break;
            }
        }
        if ($matched) {
            continue;
        }
        fwrite(STDERR, "error: unknown option: {$arg}\n");
        usage(2);
    }

    $options['kangle_dir'] = rtrim($options['kangle_dir'], '/');
    if ($options['config'] === null) {
        $options['config'] = $options['kangle_dir'] . '/etc/config.xml';
    }
    if ($options['template'] === null) {
        $options['template'] = $options['kangle_dir'] . '/etc/config-default.xml';
    }
    if ($options['output_dir'] !== null) {
        $options['output_dir'] = rtrim($options['output_dir'], '/');
    }
    if ($options['backup_suffix'] === '' || strpos($options['backup_suffix'], '/') !== false || strpos($options['backup_suffix'], "\0") !== false) {
        fwrite(STDERR, "error: invalid --backup-suffix\n");
        exit(2);
    }
    if (!$options['main'] && !$options['sites'] && count($options['access_files']) === 0) {
        fwrite(STDERR, "error: no input files were selected\n");
        exit(2);
    }
    return $options;
}

function addWarning(&$warnings, $message)
{
    if (!in_array($message, $warnings, true)) {
        $warnings[] = $message;
    }
}

function bump(&$stats, $name, $count)
{
    if (!isset($stats[$name])) {
        $stats[$name] = 0;
    }
    $stats[$name] += ($count === null ? 1 : $count);
}

function toUtf8($data, $path, &$warnings)
{
    $declaredEncoding = null;
    if (preg_match('/^\s*<\?xml[^>]*\bencoding\s*=\s*(["\'])([^"\']+)\1/i', $data, $match)) {
        $declaredEncoding = strtoupper(trim($match[2]));
    }
    $declaresLegacyChinese = in_array($declaredEncoding, array(
        'GBK', 'GB2312', 'GB18030', 'CP936', 'WINDOWS-936',
    ), true);
    if (!$declaresLegacyChinese && preg_match('//u', $data)) {
        return $data;
    }
    $converted = false;
    if (function_exists('iconv')) {
        $converted = @iconv('GB18030', 'UTF-8//IGNORE', $data);
    }
    if ($converted === false && function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($data, 'UTF-8', 'GB18030,GBK,CP936');
    }
    if ($converted === false || !preg_match('//u', $converted)) {
        throw new RuntimeException("{$path}: content is neither valid UTF-8 nor convertible GB18030/GBK");
    }
    $converted = preg_replace('/<\?xml([^>]*?)encoding\s*=\s*(["\'])[^"\']*\2([^>]*)\?>/i', '<?xml$1encoding="UTF-8"$3?>', $converted, 1);
    addWarning($warnings, 'input encoding was converted from GB18030/GBK to UTF-8');
    return $converted;
}

function loadXmlFile($path, &$raw, &$warnings)
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("cannot read {$path}");
    }
    $raw = toUtf8($raw, $path, $warnings);
    $oldUseErrors = libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput = true;
    $flags = LIBXML_NONET | LIBXML_NOBLANKS;
    $loaded = $doc->loadXML($raw, $flags);
    if (!$loaded) {
        $parts = array();
        foreach (libxml_get_errors() as $error) {
            $parts[] = trim($error->message) . ' at line ' . $error->line;
        }
        libxml_clear_errors();
        libxml_use_internal_errors($oldUseErrors);
        throw new RuntimeException("{$path}: invalid XML: " . implode('; ', $parts));
    }
    libxml_clear_errors();
    libxml_use_internal_errors($oldUseErrors);
    if (!$doc->documentElement || $doc->documentElement->tagName !== 'config') {
        throw new RuntimeException("{$path}: root element must be <config>");
    }
    return $doc;
}

function directElements($parent, $tagName)
{
    $result = array();
    foreach ($parent->childNodes as $node) {
        if ($node instanceof DOMElement && ($tagName === null || $node->tagName === $tagName)) {
            $result[] = $node;
        }
    }
    return $result;
}

function firstDirectElement($parent, $tagName)
{
    $nodes = directElements($parent, $tagName);
    return count($nodes) > 0 ? $nodes[0] : null;
}

function firstDirectByAttribute($parent, $tagName, $attribute, $value)
{
    foreach (directElements($parent, $tagName) as $node) {
        if ($node->getAttribute($attribute) === $value) {
            return $node;
        }
    }
    return null;
}

function insertRootConfiguration($root, $node)
{
    foreach ($root->childNodes as $child) {
        if (!($child instanceof DOMElement)) {
            continue;
        }
        if (in_array($child->tagName, array('request', 'response', 'vhs', 'vh'), true)) {
            $root->insertBefore($node, $child);
            return;
        }
    }
    $root->appendChild($node);
}

function ensureRootElement($doc, $root, $tagName, $attributes, &$stats)
{
    $node = firstDirectElement($root, $tagName);
    if (!$node) {
        $node = $doc->createElement($tagName);
        insertRootConfiguration($root, $node);
        bump($stats, 'added <' . $tagName . '>', 1);
    }
    foreach ($attributes as $name => $value) {
        if (!$node->hasAttribute($name)) {
            $node->setAttribute($name, $value);
            bump($stats, 'filled ' . $tagName . ' attributes', 1);
        }
    }
    return $node;
}

function nodeHasElementChildren($node)
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement) {
            return true;
        }
    }
    return false;
}

function removeTextChildren($node)
{
    $remove = array();
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText || $child instanceof DOMCdataSection) {
            $remove[] = $child;
        }
    }
    foreach ($remove as $child) {
        $node->removeChild($child);
    }
}

function disableUnsupportedModule($doc, $node, $module, &$stats, &$warnings)
{
    $raw = trim($doc->saveXML($node));
    $raw = str_replace('--', '- -', $raw);
    $comment = $doc->createComment(' migration: unsupported module "' . $module . '" disabled; original: ' . $raw . ' ');
    $node->parentNode->replaceChild($comment, $node);
    bump($stats, 'disabled unsupported modules', 1);
    addWarning($warnings, "module '{$module}' has no current equivalent and was preserved as an XML comment");
}

function normalizeRuleModules($doc, &$stats, &$warnings, &$modules)
{
    $xpath = new DOMXPath($doc);
    $nodes = array();
    foreach ($xpath->query('//chain/*') as $node) {
        if ($node instanceof DOMElement) {
            $nodes[] = $node;
        }
    }

    $aliases = array(
        'method' => 'meth',
        'file_name' => 'filename',
        'reg_file_name' => 'reg_filename',
    );
    $unsupportedMarks = array(
        'replace_content' => true,
        'reg_content' => true,
        'guest_cache' => true,
        'replace_url' => true,
        'fix_header' => true,
        'self_ip' => true,
        'url_range' => true,
    );
    $textAttributes = array(
        'acl:url' => 'url',
        'acl:path' => 'path',
        'acl:reg_path' => 'path',
        'acl:reg_param' => 'param',
        'acl:host' => 'v',
        'acl:wide_host' => 'v',
        'acl:multi_host' => 'v',
        'acl:src' => 'ip',
        'acl:srcs' => 'v',
        'acl:self' => 'ip',
        'acl:selfs' => 'v',
        'acl:self_port' => 'port',
        'acl:self_ports' => 'v',
        'acl:listen_ports' => 'v',
        'acl:dst_port' => 'port',
        'acl:meth' => 'meth',
        'acl:time' => 'time',
        'acl:file_ext' => 'v',
        'acl:file' => 'v',
        'acl:filename' => 'v',
        'acl:reg_file' => 'file',
        'acl:reg_filename' => 'filename',
        'acl:dir' => 'v',
        'acl:header' => 'val',
        'acl:auth_user' => 'v',
        'acl:ssl_serial' => 'v',
        'mark:map_redirect' => 'v',
        'mark:parent' => 'val',
        'mark:timeout' => 'v',
        'mark:mark' => 'v',
    );

    foreach ($nodes as $node) {
        $type = null;
        $module = null;
        $tag = $node->tagName;
        if (strpos($tag, 'acl_') === 0) {
            $type = 'acl';
            $module = substr($tag, 4);
        } elseif (strpos($tag, 'mark_') === 0) {
            $type = 'mark';
            $module = substr($tag, 5);
        } elseif ($tag === 'acl' || $tag === 'mark') {
            $type = $tag;
            $module = $node->getAttribute('module');
        } else {
            continue;
        }

        if ($module === 'named' && $node->hasAttribute('ref')) {
            $module = '';
        }
        if (isset($aliases[$module])) {
            $module = $aliases[$module];
            bump($stats, 'renamed legacy module aliases', 1);
        }

        if ($tag !== 'acl' && $tag !== 'mark') {
            $replacement = $doc->createElement($type);
            foreach ($node->attributes as $attribute) {
                $replacement->setAttribute($attribute->name, $attribute->value);
            }
            if ($module !== '') {
                $replacement->setAttribute('module', $module);
            } else {
                $replacement->removeAttribute('module');
            }
            while ($node->firstChild) {
                $replacement->appendChild($node->firstChild);
            }
            $node->parentNode->replaceChild($replacement, $node);
            $node = $replacement;
            bump($stats, 'converted legacy ACL/Mark nodes', 1);
        } elseif ($module !== '' && $node->getAttribute('module') !== $module) {
            $node->setAttribute('module', $module);
        }

        if ($module === '') {
            continue;
        }
        $modules[$module] = true;
        if ($type === 'mark' && isset($unsupportedMarks[$module])) {
            disableUnsupportedModule($doc, $node, $module, $stats, $warnings);
            continue;
        }

        $key = $type . ':' . $module;
        if (isset($textAttributes[$key]) && !$node->hasAttribute($textAttributes[$key]) && !nodeHasElementChildren($node)) {
            $value = trim($node->textContent);
            if ($value !== '') {
                $node->setAttribute($textAttributes[$key], $value);
                removeTextChildren($node);
                bump($stats, 'moved legacy text values to attributes', 1);
            }
        }
    }
}

function removeDirectElements($root, $tagName)
{
    $nodes = directElements($root, $tagName);
    foreach ($nodes as $node) {
        $root->removeChild($node);
    }
    return $nodes;
}

function firstNonEmptyText($nodes)
{
    foreach ($nodes as $node) {
        $value = trim($node->textContent);
        if ($value !== '') {
            return $value;
        }
    }
    return null;
}

function migrateTimeout($doc, $root, &$stats)
{
    $timeout = firstDirectElement($root, 'timeout');
    if (!$timeout) {
        $timeout = $doc->createElement('timeout');
        $timeout->setAttribute('rw', '60');
        insertRootConfiguration($root, $timeout);
        bump($stats, 'added <timeout>', 1);
    } elseif (!$timeout->hasAttribute('rw')) {
        $value = trim($timeout->textContent);
        if ($value === '') {
            $value = '60';
        }
        $timeout->setAttribute('rw', $value);
        removeTextChildren($timeout);
        bump($stats, 'converted legacy timeout', 1);
    }
    $legacy = removeDirectElements($root, 'connect_timeout');
    $connectTimeout = firstNonEmptyText($legacy);
    if ($connectTimeout !== null && !$timeout->hasAttribute('connect')) {
        $timeout->setAttribute('connect', $connectTimeout);
    }
    if (!$timeout->hasAttribute('connect')) {
        $timeout->setAttribute('connect', '10');
        bump($stats, 'filled timeout attributes', 1);
    }
    if (count($legacy) > 0) {
        bump($stats, 'migrated connect_timeout', count($legacy));
    }
}

function migrateRunAs($doc, $root, &$stats)
{
    $runAs = firstDirectElement($root, 'run_as');
    $legacy = removeDirectElements($root, 'run');
    if (!$runAs) {
        $runAs = $doc->createElement('run_as');
        if (count($legacy) > 0) {
            foreach ($legacy[0]->attributes as $attribute) {
                $runAs->setAttribute($attribute->name, $attribute->value);
            }
        }
        insertRootConfiguration($root, $runAs);
        bump($stats, count($legacy) > 0 ? 'converted <run> to <run_as>' : 'added <run_as>', 1);
    } elseif (count($legacy) > 0) {
        bump($stats, 'removed duplicate legacy <run>', count($legacy));
    }
    foreach (array('user', 'group') as $attribute) {
        if (!$runAs->hasAttribute($attribute)) {
            $runAs->setAttribute($attribute, '');
            bump($stats, 'filled run_as attributes', 1);
        }
    }
}

function migrateWorkers($doc, $root, &$stats, &$warnings)
{
    $legacyDns = removeDirectElements($root, 'worker_dns');
    $dnsValue = firstNonEmptyText($legacyDns);
    $dns = ensureRootElement($doc, $root, 'dns', array(), $stats);
    if (!$dns->hasAttribute('worker')) {
        $dns->setAttribute('worker', $dnsValue !== null ? $dnsValue : '8');
    }
    if (count($legacyDns) > 0) {
        bump($stats, 'migrated worker_dns', count($legacyDns));
    }

    $legacyIo = removeDirectElements($root, 'worker_io');
    $legacyMaxIo = removeDirectElements($root, 'max_io');
    $ioWorker = firstNonEmptyText($legacyIo);
    $ioMax = firstNonEmptyText($legacyMaxIo);
    $io = ensureRootElement($doc, $root, 'io', array(), $stats);
    if (!$io->hasAttribute('worker')) {
        $io->setAttribute('worker', $ioWorker !== null ? $ioWorker : '2');
    }
    if (!$io->hasAttribute('max')) {
        $io->setAttribute('max', $ioMax !== null ? $ioMax : '0');
    }
    if (!$io->hasAttribute('buffer')) {
        $io->setAttribute('buffer', '256K');
    }
    if (count($legacyIo) + count($legacyMaxIo) > 0) {
        bump($stats, 'migrated legacy I/O worker settings', count($legacyIo) + count($legacyMaxIo));
    }

    $ioTimeout = removeDirectElements($root, 'io_timeout');
    if (count($ioTimeout) > 0) {
        bump($stats, 'removed obsolete io_timeout', count($ioTimeout));
        addWarning($warnings, 'io_timeout is unsupported and has no direct replacement; timeout@rw controls request I/O timeout');
    }
    ensureRootElement($doc, $root, 'fiber', array('stack_size' => '0'), $stats);
}

function migrateConnect($doc, $root, &$stats)
{
    $legacy = removeDirectElements($root, 'keep_alive_count');
    $legacyValue = firstNonEmptyText($legacy);
    $connect = ensureRootElement($doc, $root, 'connect', array(), $stats);
    if (!$connect->hasAttribute('max_keep_alive')) {
        $connect->setAttribute('max_keep_alive', $legacyValue !== null ? $legacyValue : '0');
    }
    foreach (array('max' => '0', 'max_per_ip' => '0', 'per_ip_deny' => '0') as $name => $value) {
        if (!$connect->hasAttribute($name)) {
            $connect->setAttribute($name, $value);
        }
    }
    if (count($legacy) > 0) {
        bump($stats, 'migrated keep_alive_count', count($legacy));
    }
}

function migrateCompress($doc, $root, &$stats)
{
    $compress = ensureRootElement($doc, $root, 'compress', array(), $stats);
    $aliases = array(
        'only_compress_cache' => 'only_cache',
        'only_gzip_cache' => 'only_cache',
        'min_compress_length' => 'min_length',
        'min_gzip_length' => 'min_length',
    );
    foreach ($aliases as $old => $new) {
        if ($compress->hasAttribute($old)) {
            if (!$compress->hasAttribute($new)) {
                $compress->setAttribute($new, $compress->getAttribute($old));
            }
            $compress->removeAttribute($old);
            bump($stats, 'renamed legacy compress attributes', 1);
        }
    }
    foreach (array('default', 'only_gzip') as $obsolete) {
        if ($compress->hasAttribute($obsolete)) {
            $compress->removeAttribute($obsolete);
            bump($stats, 'removed obsolete compress attributes', 1);
        }
    }
    foreach (array('only_cache' => '0', 'min_length' => '512', 'gzip_level' => '5', 'br_level' => '5', 'zstd_level' => '5') as $name => $value) {
        if (!$compress->hasAttribute($name)) {
            $compress->setAttribute($name, $value);
            bump($stats, 'filled compress attributes', 1);
        }
    }
}

function migrateCacheAndLog($doc, $root, &$stats)
{
    ensureRootElement($doc, $root, 'cache', array(
        'default' => '1',
        'max_cache_size' => '10M',
        'memory' => '1G',
        'refresh_time' => '30',
        'disk' => '0',
        'max_bigobj_size' => '1G',
        'cache_part' => '1',
    ), $stats);
    ensureRootElement($doc, $root, 'log', array(
        'level' => '2',
        'rotate_size' => '100M',
        'error_rotate_size' => '100M',
        'logs_size' => '1G',
    ), $stats);
}

function migrateFirewall($doc, $root, &$stats, &$warnings)
{
    $legacyMap = array(
        'bl_time' => 'bl_time',
        'block_ip_cmd' => 'block_ip_cmd',
        'unblock_ip_cmd' => 'unblock_ip_cmd',
        'flush_ip_cmd' => 'flush_ip_cmd',
        'report_url' => 'report_url',
    );
    $values = array();
    foreach ($legacyMap as $tag => $attribute) {
        $nodes = removeDirectElements($root, $tag);
        $value = firstNonEmptyText($nodes);
        if ($value !== null) {
            $values[$attribute] = $value;
        }
        if (count($nodes) > 0) {
            bump($stats, 'migrated legacy firewall elements', count($nodes));
        }
    }

    $attackNodes = removeDirectElements($root, 'attack');
    foreach ($attackNodes as $attack) {
        if ($attack->hasAttribute('wl_time')) {
            $values['wl_time'] = $attack->getAttribute('wl_time');
        }
        if ($attack->hasAttribute('path_info') && !firstDirectElement($root, 'path_info')) {
            $pathInfo = $doc->createElement('path_info', $attack->getAttribute('path_info'));
            insertRootConfiguration($root, $pathInfo);
        }
        $other = array();
        foreach ($attack->attributes as $attribute) {
            if ($attribute->name !== 'wl_time' && $attribute->name !== 'path_info') {
                $other[] = $attribute->name;
            }
        }
        if (count($other) > 0) {
            addWarning($warnings, 'obsolete <attack> attributes were removed: ' . implode(', ', $other));
        }
    }
    if (count($attackNodes) > 0) {
        bump($stats, 'removed obsolete <attack>', count($attackNodes));
    }

    $firewall = firstDirectElement($root, 'firewall');
    if (!$firewall) {
        $firewall = $doc->createElement('firewall');
        insertRootConfiguration($root, $firewall);
        bump($stats, 'added <firewall>', 1);
    }
    foreach ($values as $name => $value) {
        if (!$firewall->hasAttribute($name)) {
            $firewall->setAttribute($name, $value);
        }
    }
    if (!$firewall->hasAttribute('bl_time')) {
        $firewall->setAttribute('bl_time', '0');
        bump($stats, 'filled firewall attributes', 1);
    }
    if (!$firewall->hasAttribute('wl_time')) {
        $firewall->setAttribute('wl_time', '1800');
        bump($stats, 'filled firewall attributes', 1);
    }
}

function ensureGlobalFlagChain($doc, $root, $attributes, &$stats)
{
    if (count($attributes) === 0) {
        return;
    }
    $request = firstDirectElement($root, 'request');
    if (!$request) {
        $request = $doc->createElement('request');
        $request->setAttribute('action', 'vhs');
        insertRootConfiguration($root, $request);
    }
    $begin = firstDirectByAttribute($request, 'table', 'name', 'BEGIN');
    if (!$begin) {
        $begin = $doc->createElement('table');
        $begin->setAttribute('name', 'BEGIN');
        $request->appendChild($begin);
    }
    $target = null;
    foreach (directElements($begin, 'chain') as $chain) {
        if ($chain->getAttribute('action') !== 'continue') {
            continue;
        }
        $elements = directElements($chain, null);
        if (count($elements) === 1 && $elements[0]->tagName === 'mark' && $elements[0]->getAttribute('module') === 'flag') {
            $target = $elements[0];
            break;
        }
    }
    if (!$target) {
        $chain = $doc->createElement('chain');
        $chain->setAttribute('action', 'continue');
        $target = $doc->createElement('mark');
        $target->setAttribute('module', 'flag');
        $chain->appendChild($target);
        if ($begin->firstChild) {
            $begin->insertBefore($chain, $begin->firstChild);
        } else {
            $begin->appendChild($chain);
        }
    }
    foreach ($attributes as $name => $value) {
        $target->setAttribute($name, $value);
    }
    bump($stats, 'migrated global proxy flags', count($attributes));
}

function migrateObsoleteRootOptions($doc, $root, &$stats, &$warnings)
{
    $flagAttributes = array();
    $viaNodes = removeDirectElements($root, 'insert_via');
    $via = firstNonEmptyText($viaNodes);
    if ($via !== null && ($via === '1' || strtolower($via) === 'on')) {
        $flagAttributes['via'] = '1';
    }
    if (count($viaNodes) > 0) {
        bump($stats, 'removed legacy insert_via', count($viaNodes));
    }

    $xffNodes = removeDirectElements($root, 'x_forwarded_for');
    $xff = firstNonEmptyText($xffNodes);
    if ($xff !== null && !($xff === '1' || strtolower($xff) === 'on')) {
        $flagAttributes['no_x_forwarded_for'] = '1';
    }
    if (count($xffNodes) > 0) {
        bump($stats, 'removed legacy x_forwarded_for', count($xffNodes));
    }
    ensureGlobalFlagChain($doc, $root, $flagAttributes, $stats);

    $obsolete = array('charset');
    foreach ($obsolete as $tag) {
        $nodes = removeDirectElements($root, $tag);
        if (count($nodes) > 0) {
            bump($stats, 'removed obsolete root elements', count($nodes));
            addWarning($warnings, "obsolete root element <{$tag}> was removed");
        }
    }
}

function ensureNamedRootNode($doc, $root, $tagName, $name, $attributes, &$stats)
{
    $node = firstDirectByAttribute($root, $tagName, 'name', $name);
    if (!$node) {
        $node = $doc->createElement($tagName);
        $node->setAttribute('name', $name);
        insertRootConfiguration($root, $node);
        bump($stats, 'added ' . $tagName . ':' . $name, 1);
    }
    foreach ($attributes as $attribute => $value) {
        if (!$node->hasAttribute($attribute)) {
            $node->setAttribute($attribute, $value);
            bump($stats, 'filled ' . $tagName . ':' . $name . ' attributes', 1);
        }
    }
    return $node;
}

function extensionConfigContains($directory, $name)
{
    if (!is_dir($directory)) {
        return false;
    }
    $files = glob(rtrim($directory, '/') . '/*.xml');
    if ($files === false) {
        return false;
    }
    foreach ($files as $file) {
        $data = @file_get_contents($file);
        if ($data !== false && preg_match('/<dso_extend\b[^>]*\bname\s*=\s*(["\'])' . preg_quote($name, '/') . '\1/i', $data)) {
            return true;
        }
    }
    return false;
}

function migrateVhs($doc, $root, $templateDoc, &$stats)
{
    $vhs = firstDirectElement($root, 'vhs');
    if (!$vhs) {
        $vhs = $doc->createElement('vhs');
        insertRootConfiguration($root, $vhs);
        bump($stats, 'added <vhs>', 1);
    }

    $legacyErrorAttributes = array();
    foreach ($vhs->attributes as $attribute) {
        if (preg_match('/^error_([0-9]{3})$/', $attribute->name, $match)) {
            $legacyErrorAttributes[$attribute->name] = array($match[1], $attribute->value);
        }
    }
    foreach ($legacyErrorAttributes as $name => $entry) {
        if (!firstDirectByAttribute($vhs, 'error', 'code', $entry[0])) {
            $error = $doc->createElement('error');
            $error->setAttribute('code', $entry[0]);
            $error->setAttribute('file', $entry[1]);
            $vhs->appendChild($error);
        }
        $vhs->removeAttribute($name);
        bump($stats, 'converted vhs error attributes', 1);
    }

    foreach (directElements($vhs, 'vh_database') as $database) {
        $duplicate = false;
        foreach (directElements($root, 'vh_database') as $existing) {
            if ($existing->getAttribute('driver') === $database->getAttribute('driver') && $existing->getAttribute('dbname') === $database->getAttribute('dbname')) {
                $duplicate = true;
                break;
            }
        }
        if (!$duplicate) {
            $root->insertBefore($database->cloneNode(true), $vhs);
        }
        $vhs->removeChild($database);
        bump($stats, 'moved vh_database to config root', 1);
    }

    $templateVhs = $templateDoc ? firstDirectElement($templateDoc->documentElement, 'vhs') : null;
    if ($templateVhs) {
        foreach (directElements($templateVhs, null) as $child) {
            $keyAttribute = null;
            if ($child->tagName === 'error') {
                $keyAttribute = 'code';
            } elseif ($child->tagName === 'index') {
                $keyAttribute = 'file';
            } elseif ($child->tagName === 'mime_type') {
                $keyAttribute = 'ext';
            }
            if ($keyAttribute === null || !$child->hasAttribute($keyAttribute)) {
                continue;
            }
            if (!firstDirectByAttribute($vhs, $child->tagName, $keyAttribute, $child->getAttribute($keyAttribute))) {
                $vhs->appendChild($doc->importNode($child, true));
                bump($stats, 'merged vhs defaults', 1);
            }
        }
    } else {
        foreach (array('403', '404') as $code) {
            if (!firstDirectByAttribute($vhs, 'error', 'code', $code)) {
                $error = $doc->createElement('error');
                $error->setAttribute('code', $code);
                $error->setAttribute('file', '/' . $code . '.html');
                $vhs->appendChild($error);
                bump($stats, 'merged vhs defaults', 1);
            }
        }
        foreach (array('index.html', 'index.htm') as $file) {
            if (!firstDirectByAttribute($vhs, 'index', 'file', $file)) {
                $index = $doc->createElement('index');
                $index->setAttribute('file', $file);
                $vhs->appendChild($index);
                bump($stats, 'merged vhs defaults', 1);
            }
        }
        if (!firstDirectByAttribute($vhs, 'mime_type', 'ext', '*')) {
            $mime = $doc->createElement('mime_type');
            $mime->setAttribute('ext', '*');
            $mime->setAttribute('type', 'text/plain');
            $vhs->appendChild($mime);
            bump($stats, 'merged vhs defaults', 1);
        }
    }
}

function ensureCoreConfiguration($doc, $root, $templateDoc, $options, $modules, &$stats, &$warnings)
{
    if ($templateDoc) {
        foreach (array('programVersion', 'configVersion') as $attribute) {
            if (!$root->hasAttribute($attribute) && $templateDoc->documentElement->hasAttribute($attribute)) {
                $root->setAttribute($attribute, $templateDoc->documentElement->getAttribute($attribute));
                bump($stats, 'filled config version attributes', 1);
            }
        }
    }

    ensureNamedRootNode($doc, $root, 'dso_extend', 'filter', array('filename' => 'bin/filter.${dso}'), $stats);
    ensureNamedRootNode($doc, $root, 'api', 'webdav', array(
        'file' => 'bin/webdav.${dso}',
        'life_time' => '60',
        'max_error_count' => '5',
    ), $stats);
    ensureNamedRootNode($doc, $root, 'api', 'whm', array('file' => 'buildin:whm'), $stats);

    if (isset($modules['anti_cc']) || isset($modules['anti_session'])) {
        if (!firstDirectByAttribute($root, 'dso_extend', 'name', 'kwaf') && !extensionConfigContains($options['kangle_dir'] . '/ext', 'kwaf')) {
            ensureNamedRootNode($doc, $root, 'dso_extend', 'kwaf', array('filename' => 'bin/kwaf.${dso}'), $stats);
            addWarning($warnings, 'anti_cc rules require kwaf; a kwaf dso_extend entry was added because no ext/*.xml loader was found');
        }
        if (!is_file($options['kangle_dir'] . '/bin/kwaf.so') && !is_file($options['kangle_dir'] . '/bin/kwaf.dll')) {
            addWarning($warnings, 'anti_cc rules were found but kwaf binary is not installed under KANGLE_DIR/bin');
        }
    }
    if (!is_file($options['kangle_dir'] . '/bin/filter.so') && !is_file($options['kangle_dir'] . '/bin/filter.dll')) {
        addWarning($warnings, 'filter extension was configured but its binary is not installed under KANGLE_DIR/bin');
    }
    if (!is_file($options['kangle_dir'] . '/bin/webdav.so') && !is_file($options['kangle_dir'] . '/bin/webdav.dll')) {
        addWarning($warnings, 'WebDAV API was configured but its binary is not installed under KANGLE_DIR/bin');
    }

    if (!firstDirectElement($root, 'request')) {
        $request = $doc->createElement('request');
        $request->setAttribute('action', 'vhs');
        insertRootConfiguration($root, $request);
        bump($stats, 'added <request>', 1);
    }
    if (!firstDirectElement($root, 'response')) {
        $response = $doc->createElement('response');
        $response->setAttribute('action', 'allow');
        insertRootConfiguration($root, $response);
        bump($stats, 'added <response>', 1);
    }
}

function migrateMainDocument($doc, $templateDoc, $options, $externalModules, &$stats, &$warnings)
{
    $root = $doc->documentElement;
    $modules = $externalModules;
    normalizeRuleModules($doc, $stats, $warnings, $modules);
    migrateTimeout($doc, $root, $stats);
    migrateRunAs($doc, $root, $stats);
    migrateWorkers($doc, $root, $stats, $warnings);
    migrateConnect($doc, $root, $stats);
    migrateCompress($doc, $root, $stats);
    migrateCacheAndLog($doc, $root, $stats);
    migrateFirewall($doc, $root, $stats, $warnings);
    migrateObsoleteRootOptions($doc, $root, $stats, $warnings);
    ensureCoreConfiguration($doc, $root, $templateDoc, $options, $modules, $stats, $warnings);
    migrateVhs($doc, $root, $templateDoc, $stats);
}

function migrateAccessDocument($doc, &$stats, &$warnings, &$modules)
{
    normalizeRuleModules($doc, $stats, $warnings, $modules);
}

function serializeDocument($doc)
{
    $doc->encoding = 'UTF-8';
    $doc->formatOutput = true;
    $xml = $doc->saveXML();
    return rtrim($xml) . "\n";
}

function discoverAccessFiles($ftpRoot, &$warnings)
{
    $files = array();
    if (!is_dir($ftpRoot)) {
        addWarning($warnings, "site root does not exist and was skipped: {$ftpRoot}");
        return $files;
    }
    try {
        $groups = new DirectoryIterator($ftpRoot);
        foreach ($groups as $group) {
            if ($group->isDot() || $group->isLink() || !$group->isDir()) {
                continue;
            }
            $sites = new DirectoryIterator($group->getPathname());
            foreach ($sites as $site) {
                if ($site->isDot() || $site->isLink() || !$site->isDir()) {
                    continue;
                }
                $candidate = $site->getPathname() . '/access.xml';
                if (is_link($candidate)) {
                    addWarning($warnings, 'symbolic-link access.xml was skipped: ' . $candidate);
                    continue;
                }
                if (is_file($candidate)) {
                    $files[] = $candidate;
                }
            }
        }
    } catch (UnexpectedValueException $e) {
        throw new RuntimeException("cannot scan {$ftpRoot}: " . $e->getMessage());
    }
    sort($files, SORT_STRING);
    return $files;
}

function outputPathFor($source, $outputDirectory)
{
    $normalized = str_replace('\\', '/', $source);
    $normalized = preg_replace('/^[A-Za-z]:\//', '$0', $normalized);
    $normalized = ltrim($normalized, '/');
    return $outputDirectory . '/' . $normalized;
}

function formatStats($stats)
{
    if (count($stats) === 0) {
        return 'XML formatting/encoding normalization only';
    }
    $parts = array();
    ksort($stats);
    foreach ($stats as $name => $count) {
        $parts[] = $name . '=' . $count;
    }
    return implode(', ', $parts);
}

function uniqueBackupPath($path, $suffix)
{
    $candidate = $path . $suffix;
    $index = 0;
    while (file_exists($candidate) || is_link($candidate)) {
        ++$index;
        $candidate = $path . $suffix . '.' . $index;
    }
    return $candidate;
}

function prepareTemporaryFile($target, $content, $sourceForMetadata)
{
    $directory = dirname($target);
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException("cannot create output directory {$directory}");
    }
    $temporary = $target . '.migrate.' . getmypid() . '.' . mt_rand(1000, 9999);
    if (@file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException("cannot write temporary file {$temporary}");
    }
    $mode = @fileperms($sourceForMetadata);
    if ($mode !== false) {
        @chmod($temporary, $mode & 0777);
    } else {
        @chmod($temporary, 0644);
    }
    if (function_exists('fileowner')) {
        $owner = @fileowner($sourceForMetadata);
        $group = @filegroup($sourceForMetadata);
        if ($owner !== false) {
            @chown($temporary, $owner);
        }
        if ($group !== false) {
            @chgrp($temporary, $group);
        }
    }
    return $temporary;
}

$options = parseArguments($argv);
$globalWarnings = array();
$inputs = array();

if ($options['main']) {
    if (!is_file($options['config'])) {
        fwrite(STDERR, "error: main config does not exist: {$options['config']}\n");
        exit(1);
    }
    $inputs[$options['config']] = 'main';
}
foreach ($options['access_files'] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "error: access file does not exist: {$file}\n");
        exit(1);
    }
    $inputs[$file] = 'access';
}
if ($options['sites']) {
    try {
        foreach (discoverAccessFiles($options['ftp_root'], $globalWarnings) as $file) {
            $inputs[$file] = 'access';
        }
    } catch (Exception $e) {
        fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

$templateDoc = null;
if (is_file($options['template'])) {
    try {
        $templateRaw = '';
        $templateWarnings = array();
        $templateDoc = loadXmlFile($options['template'], $templateRaw, $templateWarnings);
        foreach ($templateWarnings as $warning) {
            addWarning($globalWarnings, 'template: ' . $warning);
        }
    } catch (Exception $e) {
        fwrite(STDERR, 'error: cannot load template: ' . $e->getMessage() . "\n");
        exit(1);
    }
} else {
    addWarning($globalWarnings, 'config-default.xml was not found; built-in conservative defaults will be used');
}

// Convert access files first so the main config can add required DSO loaders.
$plans = array();
$allModules = array();
$ordered = array();
foreach ($inputs as $path => $type) {
    if ($type === 'access') {
        $ordered[$path] = $type;
    }
}
foreach ($inputs as $path => $type) {
    if ($type === 'main') {
        $ordered[$path] = $type;
    }
}

$hadError = false;
foreach ($ordered as $path => $type) {
    $warnings = array();
    $stats = array();
    try {
        $raw = '';
        $doc = loadXmlFile($path, $raw, $warnings);
        if ($type === 'access') {
            migrateAccessDocument($doc, $stats, $warnings, $allModules);
        } else {
            migrateMainDocument($doc, $templateDoc, $options, $allModules, $stats, $warnings);
        }
        $content = serializeDocument($doc);
        // Avoid rewriting every compatible access.xml merely because
        // DOMDocument normalizes indentation and quote style. Encoding
        // conversion is still a real change even without schema changes.
        $encodingChanged = in_array('input encoding was converted from GB18030/GBK to UTF-8', $warnings, true);
        $plans[] = array(
            'path' => $path,
            'type' => $type,
            'content' => $content,
            'changed' => (count($stats) > 0 || $encodingChanged),
            'stats' => $stats,
            'warnings' => $warnings,
        );
    } catch (Exception $e) {
        fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");
        $hadError = true;
    }
}
if ($hadError) {
    fwrite(STDERR, "No files were written because at least one input failed validation.\n");
    exit(1);
}

foreach ($globalWarnings as $warning) {
    fwrite(STDERR, '[WARN] ' . $warning . "\n");
}
$changedCount = 0;
foreach ($plans as $plan) {
    $state = $plan['changed'] ? 'CHANGE' : 'OK';
    if ($plan['changed']) {
        ++$changedCount;
    }
    fwrite(STDOUT, '[' . $state . '] ' . $plan['path'] . ' (' . $plan['type'] . ")\n");
    if ($plan['changed']) {
        fwrite(STDOUT, '         ' . formatStats($plan['stats']) . "\n");
    }
    foreach ($plan['warnings'] as $warning) {
        fwrite(STDERR, '[WARN] ' . $plan['path'] . ': ' . $warning . "\n");
    }
}

if ($options['dry_run']) {
    fwrite(STDOUT, "Dry run complete: {$changedCount} file(s) would change; no files were written.\n");
    exit(0);
}

$writePlans = array();
foreach ($plans as $plan) {
    if ($options['output_dir'] === null && !$plan['changed']) {
        continue;
    }
    $target = $options['output_dir'] === null ? $plan['path'] : outputPathFor($plan['path'], $options['output_dir']);
    $plan['target'] = $target;
    $writePlans[] = $plan;
}

$backups = array();
$temporaries = array();
try {
    if ($options['output_dir'] === null) {
        foreach ($writePlans as $plan) {
            $backup = uniqueBackupPath($plan['path'], $options['backup_suffix']);
            if (!@copy($plan['path'], $backup)) {
                throw new RuntimeException("cannot create backup {$backup}");
            }
            $backups[$plan['path']] = $backup;
        }
    }
    foreach ($writePlans as $index => $plan) {
        $temporaries[$index] = prepareTemporaryFile($plan['target'], $plan['content'], $plan['path']);
    }
    $renamed = array();
    foreach ($writePlans as $index => $plan) {
        if (!@rename($temporaries[$index], $plan['target'])) {
            foreach ($renamed as $source => $backup) {
                if ($backup !== null) {
                    @copy($backup, $source);
                }
            }
            throw new RuntimeException("cannot replace {$plan['target']}");
        }
        unset($temporaries[$index]);
        $renamed[$plan['path']] = isset($backups[$plan['path']]) ? $backups[$plan['path']] : null;
        fwrite(STDOUT, '[WRITE] ' . $plan['target']);
        if (isset($backups[$plan['path']])) {
            fwrite(STDOUT, ' (backup: ' . $backups[$plan['path']] . ')');
        }
        fwrite(STDOUT, "\n");
    }
} catch (Exception $e) {
    foreach ($temporaries as $temporary) {
        @unlink($temporary);
    }
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Migration write failed; original files were retained or restored from backups.\n");
    exit(1);
}

if ($options['reload']) {
    if ($options['output_dir'] !== null) {
        fwrite(STDERR, "[WARN] --reload was ignored because --output-dir does not update the live files.\n");
    } else {
        $binary = $options['kangle_dir'] . '/bin/kangle';
        if (!is_executable($binary)) {
            fwrite(STDERR, "[ERROR] cannot reload: {$binary} is not executable\n");
            exit(1);
        }
        $command = escapeshellarg($binary) . ' -r';
        passthru($command, $reloadStatus);
        if ($reloadStatus !== 0) {
            fwrite(STDERR, "[ERROR] Kangle reload failed with status {$reloadStatus}; backups are available for rollback.\n");
            exit(1);
        }
        fwrite(STDOUT, "Kangle configuration reloaded successfully.\n");
    }
}

fwrite(STDOUT, 'Migration complete: ' . count($writePlans) . " file(s) written.\n");
exit(0);
