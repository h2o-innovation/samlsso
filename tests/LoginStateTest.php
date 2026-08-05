<?php
/**
 *  ------------------------------------------------------------------------
 *  samlSSO
 *
 *  samlSSO was inspired by the initial work of Derrick Smith's
 *  PhpSaml. This project's intend is to address some structural issues
 *  caused by the gradual development of GLPI and the broad amount of
 *  wishes expressed by the community.
 *
 *  Copyright (C) 2024 by Chris Gralike
 *  ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of samlSSO plugin for GLPI.
 *
 * samlSSO plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * samlSSO is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with samlSSO. If not, see <http://www.gnu.org/licenses/> or
 * https://choosealicense.com/licenses/gpl-3.0/
 *
 * ------------------------------------------------------------------------
 *
 *  @package    samlSSO
 *  @version    1.3.2
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/

declare(strict_types=1);

/**
 * LoginStateTest.php
 *
 * Validates LoginState database interaction, raw timestamp conversion into 
 * localized timezones for presentation, and state phase transitions (timeouts, session expiry).
 */

namespace GlpiPlugin\Samlsso\Tests {

    require_once __DIR__ . '/Shims.php';
    require_once __DIR__ . '/../src/LoginState.php';
    require_once __DIR__ . '/TestHarness.php';

    use GlpiPlugin\Samlsso\LoginState;

    /**
     * LoginStateTest class.
     */
    class LoginStateTest {

        /**
         * Test that LoginState::getLoggingEntries correctly formats database raw UTC timestamps.
         */
        public function testLoginStateLoggingTimezones(): void {
            global $DB;
            $db = new MockDB();
            $DB = $db;

            $table = LoginState::getTable();

            // Mock database response with raw database timestamps
            $db->setResponse($table, [
                [
                    LoginState::STATE_ID => 1,
                    LoginState::IDP_ID => 2,
                    LoginState::USER_NAME => 'test_user',
                    LoginState::SESSION_ID => 'abcdef123456',
                    LoginState::SESSION_NAME => 'sid',
                    LoginState::GLPI_AUTHED => 1,
                    LoginState::SAML_AUTHED => 1,
                    LoginState::LOGIN_DATETIME => '2026-05-30 14:30:00',
                    LoginState::LAST_ACTIVITY => '2026-05-30 14:31:00',
                    LoginState::LOCATION => 'https://glpi.local/index.php',
                    LoginState::ENFORCE_LOGOFF => 0,
                    LoginState::LOGIN_FLOW_TRACE => serialize([]),
                    LoginState::PHASE => LoginState::PHASE_GLPI_AUTH
                ]
            ]);

            $entries = LoginState::getLoggingEntries(2);

            if (empty($entries)) {
                throw new \Exception("getLoggingEntries failed to return mocked database rows.");
            }

            $firstEntry = reset($entries);

            // Shims.php mocks Html::convDateTime to append ' (LOCAL)'
            $expectedLoginTime = '2026-05-30 14:30:00 (LOCAL)';
            $expectedLastClick = '2026-05-30 14:31:00 (LOCAL)';

            if ($firstEntry[LoginState::LOGIN_DATETIME] !== $expectedLoginTime) {
                throw new \Exception("loginTime was not formatted. Got: " . $firstEntry[LoginState::LOGIN_DATETIME]);
            }

            if ($firstEntry[LoginState::LAST_ACTIVITY] !== $expectedLastClick) {
                throw new \Exception("lastClickTime was not formatted. Got: " . $firstEntry[LoginState::LAST_ACTIVITY]);
            }

            echo "✅ Timezones: LoginState logging entries conversion\n";
        }

        /**
         * Test that the Twig template converts date_creation and date_mod values.
         */
        public function testTwigTemplateFormatting(): void {
            $templateFile = dirname(__DIR__) . '/templates/configForm.html.twig';
            if (!file_exists($templateFile)) {
                throw new \Exception("Twig template file not found at path: $templateFile");
            }

            $templateContent = file_get_contents($templateFile);

            // Check that we format the creation/mod dates in the warning bar at the bottom
            if (!str_contains($templateContent, 'date_creation.value|formatted_datetime')) {
                throw new \Exception("Twig template does not apply formatted_datetime filter to date_creation.");
            }

            if (!str_contains($templateContent, 'date_mod.value|formatted_datetime')) {
                throw new \Exception("Twig template does not apply formatted_datetime filter to date_mod.");
            }

            echo "✅ Timezones: Twig templates format config metadata\n";
        }

        /**
         * Test that a stale SAML login state is automatically transitioned to PHASE_TIMED_OUT.
         */
        public function testLoginStateRequestTimeout(): void {
            global $DB;
            $db = new MockDB();
            $DB = $db;

            $table = LoginState::getTable();

            // Set up mock DB response for loading by SAML request ID.
            $staleTime = gmdate('Y-m-d H:i:s', time() - 1200);

            $db->setResponse($table, [
                [
                    LoginState::STATE_ID => 1,
                    LoginState::IDP_ID => 2,
                    LoginState::USER_NAME => 'test_user',
                    LoginState::SESSION_ID => 'abcdef123456',
                    LoginState::SESSION_NAME => 'sid',
                    LoginState::GLPI_AUTHED => 0,
                    LoginState::SAML_AUTHED => 0,
                    LoginState::LOGIN_DATETIME => $staleTime,
                    LoginState::LAST_ACTIVITY => $staleTime,
                    LoginState::LOCATION => 'https://glpi.local/index.php',
                    LoginState::ENFORCE_LOGOFF => 0,
                    LoginState::SAML_REQUEST_ID => 'req_123',
                    LoginState::SAML_RESPONSE_ID => '',
                    LoginState::SAML_UNSOLICITED => 0,
                    LoginState::LOGIN_FLOW_TRACE => serialize([]),
                    LoginState::PHASE => LoginState::PHASE_SAML_ACS,
                    LoginState::REDIRECT => '',
                    LoginState::CLIENT_IP => '127.0.0.1',
                    LoginState::CLIENT_COUNTRY => 'US',
                ]
            ]);

            // Mock ConfigEntity request timeout field response
            \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields[\GlpiPlugin\Samlsso\Config\MockConfigEntity::REQUEST_TIMEOUT] = 15;

            // Instantiate LoginState using the SAML Request ID (InResponseTo)
            $loginState = new LoginState('req_123');

            if ($loginState->getPhase() !== LoginState::PHASE_TIMED_OUT) {
                throw new \Exception("Request awaiting ACS that is older than the timeout limit did not transition to PHASE_TIMED_OUT. Got: " . $loginState->getPhase());
            }

            echo "✅ LoginState: request timeout fallback verification\n";
        }

        /**
         * Test that LoginState transitions PHASE_GLPI_AUTH to PHASE_INITIAL when
         * the GLPI session has expired (negative path), but keeps PHASE_GLPI_AUTH
         * when the session remains active (positive path).
         */
        public function testLoginStateSessionExpiry(): void {
            global $DB;
            $db = new MockDB();
            $DB = $db;

            $table = LoginState::getTable();

            // Set up mock DB response for loading by current PHP session ID.
            $now = gmdate('Y-m-d H:i:s');
            $db->setResponse($table, [
                [
                    LoginState::STATE_ID => 1,
                    LoginState::IDP_ID => 2,
                    LoginState::USER_NAME => 'test_user',
                    LoginState::SESSION_ID => session_id(),
                    LoginState::SESSION_NAME => 'sid',
                    LoginState::GLPI_AUTHED => 1,
                    LoginState::SAML_AUTHED => 1,
                    LoginState::LOGIN_DATETIME => $now,
                    LoginState::LAST_ACTIVITY => $now,
                    LoginState::LOCATION => 'https://glpi.local/index.php',
                    LoginState::ENFORCE_LOGOFF => 0,
                    LoginState::SAML_REQUEST_ID => '',
                    LoginState::SAML_RESPONSE_ID => '',
                    LoginState::SAML_UNSOLICITED => 0,
                    LoginState::LOGIN_FLOW_TRACE => serialize([]),
                    LoginState::PHASE => LoginState::PHASE_GLPI_AUTH,
                    LoginState::REDIRECT => '',
                    LoginState::CLIENT_IP => '127.0.0.1',
                    LoginState::CLIENT_COUNTRY => 'US',
                ]
            ]);

            // --- POSITIVE PATH: Session is active and authenticated in GLPI ---
            $_SESSION[LoginState::SESSION_GLPI_NAME_ACCESSOR] = 'test_user';
            $_SESSION[LoginState::SESSION_VALID_ID_ACCESSOR] = session_id();

            $loginStateActive = new LoginState();
            if ($loginStateActive->getPhase() !== LoginState::PHASE_GLPI_AUTH) {
                throw new \Exception("Active session incorrectly changed phase. Expected PHASE_GLPI_AUTH (4), got: " . $loginStateActive->getPhase());
            }

            // --- NEGATIVE PATH: Session is expired (empty $_SESSION) ---
            unset($_SESSION[LoginState::SESSION_GLPI_NAME_ACCESSOR]);
            unset($_SESSION[LoginState::SESSION_VALID_ID_ACCESSOR]);

            $loginStateExpired = new LoginState();
            if ($loginStateExpired->getPhase() !== LoginState::PHASE_INITIAL) {
                throw new \Exception("Expired session failed to transition phase. Expected PHASE_INITIAL (1), got: " . $loginStateExpired->getPhase());
            }

            echo "✅ LoginState: session expiration phase transition (positive & negative paths)\n";
        }

        /**
         * Test that LoginState location falls back to 'UNKNOWN' if parse_url fails.
         */
        public function testLoginStateLocationFallback(): void {
            global $DB;
            $db = new MockDB();
            $DB = $db;

            // Set request URI to something that causes parse_url to fail/return false or null
            $_SERVER['REQUEST_URI'] = 'http://:80';

            $loginState = new LoginState();

            // Use reflection to verify private state property
            $refObj = new \ReflectionObject($loginState);
            $refProp = $refObj->getProperty('state');
            $refProp->setAccessible(true);
            $state = $refProp->getValue($loginState);

            if (($state[LoginState::LOCATION] ?? '') !== 'UNKNOWN') {
                throw new \Exception("Expected location fallback to 'UNKNOWN' when parse_url fails, got: " . var_export($state[LoginState::LOCATION] ?? null, true));
            }

            // Clean up
            unset($_SERVER['REQUEST_URI']);

            echo "✅ LoginState: location fallback to UNKNOWN on parse_url failure\n";
        }

        /**
         * Test that inactivity timeout correctly transitions the phase to PHASE_TIMED_OUT
         * and evalGlpiAuth does not overwrite it when the session is still active in PHP.
         */
        public function testLoginStateInactivityTimeoutNoOverwrite(): void {
            global $DB;
            $db = new MockDB();
            $DB = $db;

            $table = LoginState::getTable();
            $staleTime = gmdate('Y-m-d H:i:s', time() - 1200); // 20 mins ago

            $db->setResponse($table, [
                [
                    LoginState::STATE_ID => 1,
                    LoginState::IDP_ID => 2,
                    LoginState::USER_NAME => 'test_user',
                    LoginState::SESSION_ID => session_id(),
                    LoginState::SESSION_NAME => 'sid',
                    LoginState::GLPI_AUTHED => 1,
                    LoginState::SAML_AUTHED => 1,
                    LoginState::LOGIN_DATETIME => $staleTime,
                    LoginState::LAST_ACTIVITY => $staleTime,
                    LoginState::LOCATION => 'https://glpi.local/index.php',
                    LoginState::ENFORCE_LOGOFF => 0,
                    LoginState::SAML_REQUEST_ID => '',
                    LoginState::SAML_RESPONSE_ID => '',
                    LoginState::SAML_UNSOLICITED => 0,
                    LoginState::LOGIN_FLOW_TRACE => serialize([]),
                    LoginState::PHASE => LoginState::PHASE_GLPI_AUTH,
                    LoginState::REDIRECT => '',
                    LoginState::CLIENT_IP => '127.0.0.1',
                    LoginState::CLIENT_COUNTRY => 'US',
                ]
            ]);

            // Set up $_SESSION variables so the session appears active
            $_SESSION[LoginState::SESSION_GLPI_NAME_ACCESSOR] = 'test_user';
            $_SESSION[LoginState::SESSION_VALID_ID_ACCESSOR] = session_id();

            // Mock ConfigEntity inactivity timeout response
            \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields[\GlpiPlugin\Samlsso\Config\MockConfigEntity::INACTIVITY_TIMEOUT] = 15;

            $loginState = new LoginState();

            if ($loginState->getPhase() !== LoginState::PHASE_TIMED_OUT) {
                throw new \Exception("Inactivity timeout did not transition phase to PHASE_TIMED_OUT, or evalGlpiAuth incorrectly overwrote it. Got phase: " . $loginState->getPhase());
            }

            // Clean up
            unset($_SESSION[LoginState::SESSION_GLPI_NAME_ACCESSOR]);
            unset($_SESSION[LoginState::SESSION_VALID_ID_ACCESSOR]);
            \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields = [];

            echo "✅ LoginState: inactivity timeout transitions to TIMED_OUT and is not overwritten\n";
        }

        /**
         * Test that states with empty/invalid loginTime or lastClickTime are NOT
         * incorrectly transitioned to PHASE_TIMED_OUT. strtotime('') returns false,
         * which previously made time() - 0 exceed any timeout value, expiring states
         * instantly and producing spurious 'SAML authentication request timed out'
         * errors on re-authentication.
         */
        public function testLoginStateEmptyTimestampsDoNotTimeout(): void {
            global $DB;
            $db = new MockDB();
            $DB = $db;

            $table = LoginState::getTable();

            // Empty timestamps as observed in production state rows
            $db->setResponse($table, [
                [
                    LoginState::STATE_ID => 1,
                    LoginState::IDP_ID => 2,
                    LoginState::USER_NAME => 'test_user',
                    LoginState::SESSION_ID => 'abcdef123456',
                    LoginState::SESSION_NAME => 'sid',
                    LoginState::GLPI_AUTHED => 0,
                    LoginState::SAML_AUTHED => 0,
                    LoginState::LOGIN_DATETIME => '',
                    LoginState::LAST_ACTIVITY => '',
                    LoginState::LOCATION => 'https://glpi.local/index.php',
                    LoginState::ENFORCE_LOGOFF => 0,
                    LoginState::SAML_REQUEST_ID => 'req_123',
                    LoginState::SAML_RESPONSE_ID => '',
                    LoginState::SAML_UNSOLICITED => 0,
                    LoginState::LOGIN_FLOW_TRACE => serialize([]),
                    LoginState::PHASE => LoginState::PHASE_SAML_ACS,
                    LoginState::REDIRECT => '',
                    LoginState::CLIENT_IP => '127.0.0.1',
                    LoginState::CLIENT_COUNTRY => 'US',
                ]
            ]);

            \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields[\GlpiPlugin\Samlsso\Config\MockConfigEntity::REQUEST_TIMEOUT] = 15;

            $loginState = new LoginState('req_123');

            if ($loginState->getPhase() !== LoginState::PHASE_SAML_ACS) {
                throw new \Exception("Empty loginTime incorrectly triggered a request timeout. Expected PHASE_SAML_ACS (2), got: " . $loginState->getPhase());
            }

            // Also verify an empty lastClickTime does not trigger the inactivity
            // timeout on an authenticated session.
            $db->setResponse($table, [
                [
                    LoginState::STATE_ID => 2,
                    LoginState::IDP_ID => 2,
                    LoginState::USER_NAME => 'test_user',
                    LoginState::SESSION_ID => session_id(),
                    LoginState::SESSION_NAME => 'sid',
                    LoginState::GLPI_AUTHED => 1,
                    LoginState::SAML_AUTHED => 1,
                    LoginState::LOGIN_DATETIME => '',
                    LoginState::LAST_ACTIVITY => '',
                    LoginState::LOCATION => 'https://glpi.local/index.php',
                    LoginState::ENFORCE_LOGOFF => 0,
                    LoginState::SAML_REQUEST_ID => '',
                    LoginState::SAML_RESPONSE_ID => '',
                    LoginState::SAML_UNSOLICITED => 0,
                    LoginState::LOGIN_FLOW_TRACE => serialize([]),
                    LoginState::PHASE => LoginState::PHASE_GLPI_AUTH,
                    LoginState::REDIRECT => '',
                    LoginState::CLIENT_IP => '127.0.0.1',
                    LoginState::CLIENT_COUNTRY => 'US',
                ]
            ]);

            \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields = [
                \GlpiPlugin\Samlsso\Config\MockConfigEntity::INACTIVITY_TIMEOUT => 15
            ];
            $_SESSION[LoginState::SESSION_GLPI_NAME_ACCESSOR] = 'test_user';
            $_SESSION[LoginState::SESSION_VALID_ID_ACCESSOR] = session_id();

            $loginStateAuthed = new LoginState();

            if ($loginStateAuthed->getPhase() !== LoginState::PHASE_GLPI_AUTH) {
                throw new \Exception("Empty lastClickTime incorrectly triggered the inactivity timeout. Expected PHASE_GLPI_AUTH (4), got: " . $loginStateAuthed->getPhase());
            }

            // Clean up
            unset($_SESSION[LoginState::SESSION_GLPI_NAME_ACCESSOR]);
            unset($_SESSION[LoginState::SESSION_VALID_ID_ACCESSOR]);
            \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields = [];

            echo "✅ LoginState: empty timestamps do not trigger premature timeouts\n";
        }

        /**
         * Test that setRequestStart() refreshes the loginTime reference so that
         * re-authentication on a long-lived state row is not prematurely expired
         * by the request timeout.
         */
        public function testLoginStateRequestStartRefreshesTimeout(): void {
            global $DB;
            $db = new MockDB();
            $DB = $db;

            $table = LoginState::getTable();
            $oldTime = gmdate('Y-m-d H:i:s', time() - 3600); // 1 hour ago

            // Existing session state row that is about to be re-authenticated
            $db->setResponse($table, [
                [
                    LoginState::STATE_ID => 1,
                    LoginState::IDP_ID => 2,
                    LoginState::USER_NAME => 'test_user',
                    LoginState::SESSION_ID => session_id(),
                    LoginState::SESSION_NAME => 'sid',
                    LoginState::GLPI_AUTHED => 0,
                    LoginState::SAML_AUTHED => 0,
                    LoginState::LOGIN_DATETIME => $oldTime,
                    LoginState::LAST_ACTIVITY => $oldTime,
                    LoginState::LOCATION => 'https://glpi.local/index.php',
                    LoginState::ENFORCE_LOGOFF => 0,
                    LoginState::SAML_REQUEST_ID => '',
                    LoginState::SAML_RESPONSE_ID => '',
                    LoginState::SAML_UNSOLICITED => 0,
                    LoginState::LOGIN_FLOW_TRACE => serialize([]),
                    LoginState::PHASE => LoginState::PHASE_INITIAL,
                    LoginState::REDIRECT => '',
                    LoginState::CLIENT_IP => '127.0.0.1',
                    LoginState::CLIENT_COUNTRY => 'US',
                ]
            ]);

            \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields[\GlpiPlugin\Samlsso\Config\MockConfigEntity::REQUEST_TIMEOUT] = 15;

            // Load the existing (old) session state by PHP session id
            $loginState = new LoginState();

            // Issue a new SAML AuthNRequest -> refresh the request start time
            if ($loginState->setRequestStart() !== true) {
                throw new \Exception("setRequestStart() did not return true");
            }

            // Verify loginTime was refreshed to ~now
            $refObj = new \ReflectionObject($loginState);
            $refProp = $refObj->getProperty('state');
            $refProp->setAccessible(true);
            $state = $refProp->getValue($loginState);
            $requestStart = strtotime($state[LoginState::LOGIN_DATETIME] ?? '');
            if ($requestStart === false || (time() - $requestStart) > 120) {
                throw new \Exception("setRequestStart() did not refresh loginTime. Got: " . var_export($state[LoginState::LOGIN_DATETIME] ?? null, true));
            }

            // Simulate the ACS reload reading the refreshed timestamp back from the DB
            $db->setResponse($table, [
                [
                    LoginState::STATE_ID => 1,
                    LoginState::IDP_ID => 2,
                    LoginState::USER_NAME => 'test_user',
                    LoginState::SESSION_ID => session_id(),
                    LoginState::SESSION_NAME => 'sid',
                    LoginState::GLPI_AUTHED => 0,
                    LoginState::SAML_AUTHED => 0,
                    LoginState::LOGIN_DATETIME => $state[LoginState::LOGIN_DATETIME],
                    LoginState::LAST_ACTIVITY => $state[LoginState::LAST_ACTIVITY],
                    LoginState::LOCATION => 'https://glpi.local/index.php',
                    LoginState::ENFORCE_LOGOFF => 0,
                    LoginState::SAML_REQUEST_ID => 'req_123',
                    LoginState::SAML_RESPONSE_ID => '',
                    LoginState::SAML_UNSOLICITED => 0,
                    LoginState::LOGIN_FLOW_TRACE => serialize([]),
                    LoginState::PHASE => LoginState::PHASE_SAML_ACS,
                    LoginState::REDIRECT => '',
                    LoginState::CLIENT_IP => '127.0.0.1',
                    LoginState::CLIENT_COUNTRY => 'US',
                ]
            ]);

            $loginStateAcs = new LoginState('req_123');

            if ($loginStateAcs->getPhase() !== LoginState::PHASE_SAML_ACS) {
                throw new \Exception("Re-authentication after setRequestStart() was incorrectly expired. Expected PHASE_SAML_ACS (2), got: " . $loginStateAcs->getPhase());
            }

            \GlpiPlugin\Samlsso\Config\MockConfigEntity::$mockFields = [];

            echo "✅ LoginState: setRequestStart() refreshes timeout reference for re-auth\n";
        }
    }
}

namespace {
    $test = new GlpiPlugin\Samlsso\Tests\LoginStateTest();
    try {
        $test->testLoginStateLoggingTimezones();
        $test->testTwigTemplateFormatting();
        $test->testLoginStateRequestTimeout();
        $test->testLoginStateSessionExpiry();
        $test->testLoginStateLocationFallback();
        $test->testLoginStateInactivityTimeoutNoOverwrite();
        $test->testLoginStateEmptyTimestampsDoNotTimeout();
        $test->testLoginStateRequestStartRefreshesTimeout();
        $test = null;
    } catch (\Exception $e) {
        echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
