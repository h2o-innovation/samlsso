<?php

declare(strict_types=1);

/**
 *  ------------------------------------------------------------------------
 *  samlSSO
 *
 *  samlSSO was inspired by the initial work of Derrick Smith's
 *  PhpSaml. This project's intend is to address some structural issues
 *  caused by the gradual development of GLPI and the broad amount of
 *  wishes expressed by the community.
 *
 *  Copyright (C) 2026 by DonutsNL
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
 *  @version    1.4.0
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.5.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso;

use CommonGLPI;
use Session;
use GlpiPlugin\Samlsso\Config\ClaimMapEntity;
use GlpiPlugin\Samlsso\Config\ClaimMapItem;
use Glpi\Application\View\TemplateRenderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * UserSync adds a plugin tab to the GLPI User form that lets an administrator
 * manually (re)synchronize a user's mapped fields from the samlSSO claim
 * mappings.
 *
 * The tab resolves the Identity Provider that applies to the user (by matching
 * the user's email domain against a configured IdP, or falling back to the
 * single active configuration), previews the fields that would be derived from
 * the user's email address through the configured Regex Transforms, and applies
 * those fields on demand.
 *
 * GLPI core is intentionally left untouched; the tab is injected through the
 * standard plugin "addtabon" registration mechanism.
 *
 * @since 1.5.0
 * @see   \GlpiPlugin\Samlsso\Config\ClaimMapEntity
 */
class UserSync extends CommonGLPI
{
    /**
     * Map of a ClaimMap target field to the GLPI user column it is written to
     * during a manual synchronization.
     */
    private const FIELD_MAP = [
        ClaimMapItem::FIELD_USERNAME  => 'name',
        ClaimMapItem::FIELD_REALNAME  => 'realname',
        ClaimMapItem::FIELD_FIRSTNAME => 'firstname',
        ClaimMapItem::FIELD_PHONE     => 'phone',
        ClaimMapItem::FIELD_MOBILE    => 'mobile',
    ];

    /**
     * Return the friendly name of this tab provider.
     *
     * @param int $nb Number of items (GLPI convention).
     * @return string
     */
    public static function getTypeName($nb = 0): string
    {
        return __('samlSSO Synchronization', PLUGIN_NAME);
    }

    /**
     * Return the icon used for the tab.
     *
     * @return string
     */
    public static function getIcon(): string
    {
        return 'ti ti-refresh';
    }

    /**
     * Register the tab name for the User form.
     *
     * @param CommonGLPI $item         Item owning the tab (expected: User).
     * @param int        $withtemplate Template mode (GLPI convention).
     * @return string
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof \User && (int) $item->getID() > 0) {
            return __('samlSSO Synchronization', PLUGIN_NAME);
        }
        return '';
    }

    /**
     * Render the tab content for the User form.
     *
     * @param CommonGLPI $item         Item owning the tab (expected: User).
     * @param int        $tabnum       Tab number (GLPI convention).
     * @param int        $withtemplate Template mode (GLPI convention).
     * @return bool
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof \User && (int) $item->getID() > 0) {
            self::showForUser($item);
        }
        return true;
    }

    /**
     * Render the synchronization tab for a specific user.
     *
     * @param \User $user The user being displayed.
     * @return void
     */
    private static function showForUser(\User $user): void
    {
        $userId = (int) $user->getID();
        $email  = self::getUserEmail($userId);
        $idpId  = self::resolveIdpId($email);

        $tplVars = [
            'plugin'        => PLUGIN_NAME,
            'user_id'       => $userId,
            'user_name'     => (string) ($user->fields[ClaimMapItem::FIELD_USERNAME] ?? ''), //NOSONAR - 'name' column
            'email'         => $email,
            'idp_name'      => '',
            'preview'       => [],
            'sync_url'      => PLUGIN_SAMLSSO_WEBDIR . 'front/usersync',
            'csrf_token'    => Session::getNewCSRFToken(),
        ];

        if ($idpId > 0) {
            $config = new Config();
            if ($config->getFromDB($idpId)) {
                $tplVars['idp_name'] = (string) ($config->fields[Config\ConfigEntity::NAME] ?? '');
            }
            $tplVars['preview'] = self::buildPreview($user, $idpId, $email);
        }

        TemplateRenderer::getInstance()->display('@samlsso/userSync.html.twig', $tplVars);
    }

    /**
     * Handle the manual synchronization request.
     *
     * Verifies the CSRF token and the update rights, derives the mapped fields
     * from the user's email address and applies them to the user.
     *
     * @param Request $request Incoming request carrying users_id and CSRF token.
     * @return Response Redirect back to the user form.
     */
    public static function handleSync(Request $request): Response
    {
        $userId = (int) $request->request->get('users_id', 0);

        $redirect = new RedirectResponse(
            $userId > 0 ? \User::getFormURLWithID($userId) : PLUGIN_SAMLSSO_WEBDIR . 'front/config'
        );

        if (!Session::checkCSRF($request->request->all())) {
            return $redirect;
        }

        if (!Session::haveRight('user', UPDATE)) {
            Session::addMessageAfterRedirect(
                __('You do not have permission to synchronize this user.', PLUGIN_NAME),
                false,
                ERROR
            );
            return $redirect;
        }

        $user = new \User();
        if ($userId <= 0 || !$user->getFromDB($userId)) {
            Session::addMessageAfterRedirect(
                __('Unable to load the GLPI user for synchronization.', PLUGIN_NAME),
                false,
                ERROR
            );
            return $redirect;
        }

        if (!$user->canUpdate()) {
            Session::addMessageAfterRedirect(
                __('You do not have permission to update this user.', PLUGIN_NAME),
                false,
                ERROR
            );
            return $redirect;
        }

        $email = self::getUserEmail($userId);
        $idpId = self::resolveIdpId($email);
        if ($idpId <= 0) {
            Session::addMessageAfterRedirect(
                __('No samlSSO Identity Provider could be resolved for this user. Configure a matching email domain or a single active IdP.', PLUGIN_NAME),
                false,
                ERROR
            );
            return $redirect;
        }

        $input = self::buildDerivedInput($user, $idpId, $email);
        if (count($input) === 0) {
            Session::addMessageAfterRedirect(
                __('No claim mapping produced a value for this user. Verify the Regex Transforms in the IdP claim mapping.', PLUGIN_NAME),
                false,
                WARNING
            );
            return $redirect;
        }

        if ($user->update($input)) {
            $updated = [];
            foreach ($input as $column => $value) {
                if ($column === 'id') {
                    continue;
                }
                $updated[] = $column . ' = ' . $value;
            }
            Session::addMessageAfterRedirect(
                sprintf(
                    __('samlSSO synchronization applied: %s', PLUGIN_NAME),
                    implode(', ', $updated)
                )
            );
        } else {
            Session::addMessageAfterRedirect(
                __('samlSSO synchronization failed while updating the GLPI user.', PLUGIN_NAME),
                false,
                ERROR
            );
        }

        return $redirect;
    }

    /**
     * Resolve the Identity Provider configuration that applies to an email.
     *
     * Resolution order: match the email domain against a configured IdP, then
     * fall back to the single active configuration (if exactly one exists).
     *
     * @param string $email The user email address (may be empty).
     * @return int The resolved IdP configuration id, or 0 when none applies.
     */
    private static function resolveIdpId(string $email): int
    {
        if ($email !== '') {
            $idpId = Config::getConfigIdByEmailDomain($email);
            if ($idpId > 0) {
                return $idpId;
            }
        }
        return Config::getIsOnlyOneConfig();
    }

    /**
     * Retrieve the primary email address of a GLPI user.
     *
     * Returns the default email from glpi_useremails, falling back to the first
     * available email and finally to the username when it is itself an email.
     *
     * @param int $userId The GLPI user id.
     * @return string The email address, or an empty string when none is found.
     */
    private static function getUserEmail(int $userId): string
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => 'glpi_useremails',
            'WHERE' => ['users_id' => $userId],
            'ORDER' => ['is_default DESC'],
        ]);

        foreach ($iterator as $row) {
            if (!empty($row['email'])) {
                return (string) $row['email'];
            }
        }

        $user = new \User();
        if ($user->getFromDB($userId) && filter_var($user->fields['name'], FILTER_VALIDATE_EMAIL)) {
            return (string) $user->fields['name'];
        }

        return '';
    }

    /**
     * Build a preview of the fields that would be derived for a user.
     *
     * @param \User  $user  The user being displayed.
     * @param int    $idpId The resolved IdP configuration id.
     * @param string $email The user email used as the transform source.
     * @return array List of rows with glpi_field, current value and derived value.
     */
    private static function buildPreview(\User $user, int $idpId, string $email): array
    {
        $claimMap = new ClaimMapEntity($idpId);
        $rows     = [];

        foreach ($claimMap->getMappings() as $mapping) {
            $field = (string) ($mapping['glpi_field'] ?? '');
            $regex = (string) ($mapping[ClaimMapItem::REGEX] ?? '');
            if ($field === '' || $regex === '' || !isset(self::FIELD_MAP[$field])) {
                continue;
            }

            $column  = self::FIELD_MAP[$field];
            $derived = self::deriveValue($email, $mapping);

            $rows[] = [
                'glpi_field' => $field,
                'column'     => $column,
                'current'    => (string) ($user->fields[$column] ?? ''),
                'derived'    => $derived,
            ];
        }

        return $rows;
    }

    /**
     * Build the GLPI user update input from the derived claim mapping values.
     *
     * @param \User  $user  The user to synchronize.
     * @param int    $idpId The resolved IdP configuration id.
     * @param string $email The user email used as the transform source.
     * @return array The update input (always contains the user id).
     */
    private static function buildDerivedInput(\User $user, int $idpId, string $email): array
    {
        $claimMap = new ClaimMapEntity($idpId);
        $input    = ['id' => (int) $user->getID()];

        foreach ($claimMap->getMappings() as $mapping) {
            $field = (string) ($mapping['glpi_field'] ?? '');
            $regex = (string) ($mapping[ClaimMapItem::REGEX] ?? '');
            if ($field === '' || $regex === '' || !isset(self::FIELD_MAP[$field])) {
                continue;
            }

            $value = self::deriveValue($email, $mapping);
            if ($value === '') {
                continue;
            }

            $input[self::FIELD_MAP[$field]] = $value;
        }

        return $input;
    }

    /**
     * Derive a mapped value from an email address using the mapping transform.
     *
     * @param string $email   The transform source value.
     * @param array  $mapping The mapping row (regex/replace/default).
     * @return string The derived value, or an empty string when no value applies.
     */
    private static function deriveValue(string $email, array $mapping): string
    {
        $regex   = (string) ($mapping[ClaimMapItem::REGEX] ?? '');
        $replace = (string) ($mapping[ClaimMapItem::REPLACE] ?? '');
        $default = (string) ($mapping['default_value'] ?? '');

        $value = ClaimMapEntity::transform($email, $regex, $replace);
        if ($value === '') {
            $value = $default;
        }

        return $value;
    }
}
