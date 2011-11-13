<?php
/**
 * Records clicks and clean the URL with Horde::externalUrl().
 *
 * $Horde: trean/redirect.php,v 1.13 2009/06/10 05:25:16 slusarz Exp $
 *
 * Copyright 2005-2009 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.horde.org/licenses/gpl.
 *
 * @author Ben Chavet <ben@horde.org>
 */

require_once dirname(__FILE__) . '/lib/Application.php';
Horde_Registry::appInit('trean');

$bookmark_id = Horde_Util::getFormData('b');
if (!$bookmark_id) {
    exit;
}

try {
    $bookmark = $trean_gateway->getBookmark($bookmark_id);
    ++$bookmark->clicks;
    $bookmark->save();
    header('Location: ' . Horde::externalUrl($bookmark->url));
} catch (Exception $e) {
}
