<?php
/**
 * Tests for the PGP binary backend.
 *
 * @author     Michael Slusarz <slusarz@horde.org>
 * @category   Horde
 * @copyright  2015 Horde LLC
 * @ignore
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package    Crypt
 * @subpackage UnitTests
 */
class Horde_Crypt_Pgp_BinaryTest
extends Horde_Crypt_Pgp_TestBase
{
    protected function _setUp()
    {
        $c = self::getConfig('CRYPTPGP_TEST_CONFIG', __DIR__ . '/../');
        $gnupg = isset($c['gnupg'])
            ? $c['gnupg']
            : '/usr/bin/gpg';

        if (!is_executable($gnupg)) {
            $this->markTestSkipped(sprintf(
                'GPG binary not found at %s.',
                $gnupg
            ));
        }

        return array(new Horde_Crypt_Pgp_Backend_Binary($gnupg));
    }

}
