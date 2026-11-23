<?php
/**
 * Form parts for the role "Externe Partner" in admin/users_create.php and users_edit.php:
 * role select (incl. partner when available) and the per-partner permissions
 * (features + events), stored in users.partner_permissions.
 */

namespace App\Helpers;

use App\Core\Permission;
use App\Models\Event;

class PartnerPermissionForm
{
    /** Roles an admin may assign; partner only once the migration has run. */
    public static function allowedRoles(): array
    {
        $roles = ['presales' => 'Vorverkauf', 'admin' => 'Administrator'];
        if (Permission::partnerRoleAvailable()) {
            $roles['partner'] = 'Externer Partner';
        }
        return $roles;
    }

    /** Validate the posted role. */
    public static function postedRole(string $fallback = 'presales'): string
    {
        $role = (string)($_POST['role'] ?? $fallback);
        return array_key_exists($role, self::allowedRoles()) ? $role : $fallback;
    }

    /** Role <select> with a script that shows the partner block only for role partner. */
    public static function roleSelect(string $current): string
    {
        $html = '<select class="form-select" id="role" name="role" onchange="togglePartnerPermissions()">';
        foreach (self::allowedRoles() as $value => $label) {
            $html .= '<option value="' . $value . '"' . ($current === $value ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
        }
        $html .= '</select>';
        if (Permission::partnerRoleAvailable() && !Permission::partnerRoleActive()) {
            $html .= '<div class="form-text text-warning"><i class="bi bi-exclamation-triangle"></i> Die Rolle „Externer Partner" ist unter <em>Einstellungen → Funktionen</em> nicht aktiviert – Partner können sich erst nach dem Aktivieren anmelden.</div>';
        }
        return $html;
    }

    /**
     * Partner permissions block. $stored = users.partner_permissions (JSON) or null.
     * When the form was posted, the posted values are shown again.
     */
    public static function render(?string $stored, string $role): string
    {
        if (!Permission::partnerRoleAvailable()) {
            return '';
        }
        $posted = $_SERVER['REQUEST_METHOD'] === 'POST';
        $parsed = Permission::parseUserPermissions($stored);
        $install = Permission::installFeatures();

        $selectedFeatures = $posted
            ? array_map('strval', (array)($_POST['partner_features'] ?? []))
            : ($parsed['features'] ?? $install);
        $restrictEvents = $posted ? !empty($_POST['partner_restrict_events']) : $parsed['event_ids'] !== null;
        $selectedEvents = $posted
            ? array_map('intval', (array)($_POST['partner_event_ids'] ?? []))
            : ($parsed['event_ids'] ?? []);

        $features = '';
        foreach (Permission::FEATURES as $key => [$label, $description]) {
            $enabledByInstall = in_array($key, $install, true);
            $checked = in_array($key, $selectedFeatures, true) && $enabledByInstall;
            $features .= '
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="partner_features[]" value="' . $key . '" id="pf_' . $key . '"'
                        . ($checked ? ' checked' : '') . ($enabledByInstall ? '' : ' disabled') . '>
                    <label class="form-check-label" for="pf_' . $key . '">
                        <strong>' . htmlspecialchars($label) . '</strong>'
                        . ($enabledByInstall ? '' : ' <span class="badge bg-secondary">in den Einstellungen deaktiviert</span>') . '
                        <div class="small text-muted">' . htmlspecialchars($description) . '</div>
                    </label>
                </div>';
        }

        $events = '';
        foreach ((new Event())->getAll(true, true) as $event) {
            $id = (int)$event['id'];
            $events .= '
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="partner_event_ids[]" value="' . $id . '" id="pe_' . $id . '"'
                            . (in_array($id, $selectedEvents, true) ? ' checked' : '') . '>
                        <label class="form-check-label" for="pe_' . $id . '">' . htmlspecialchars($event['name']) . '</label>
                    </div>';
        }
        if ($events === '') {
            $events = '<div class="text-muted small">Keine aktiven Veranstaltungen.</div>';
        }

        return '
            <div id="partner-permissions" class="card mb-3 border-info"' . ($role === 'partner' ? '' : ' style="display:none"') . '>
                <div class="card-header bg-info-subtle"><i class="bi bi-shop"></i> Rechte des externen Partners</div>
                <div class="card-body">
                    <p class="small text-muted mb-2">Es stehen nur Funktionen zur Wahl, die unter <em>Einstellungen → Funktionen → Externe Partner</em> für diese Installation freigegeben sind.</p>
                    ' . $features . '
                    <hr>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="partner_restrict_events" name="partner_restrict_events" value="1"'
                            . ($restrictEvents ? ' checked' : '') . ' onchange="document.getElementById(\'partner-event-list\').style.display = this.checked ? \'\' : \'none\'">
                        <label class="form-check-label" for="partner_restrict_events"><strong>Nur bestimmte Veranstaltungen</strong>
                            <div class="small text-muted">Aus: Der Partner darf für alle aktiven Veranstaltungen verkaufen.</div></label>
                    </div>
                    <div id="partner-event-list" class="ms-4"' . ($restrictEvents ? '' : ' style="display:none"') . '>' . $events . '</div>
                </div>
            </div>
            <script>
            function togglePartnerPermissions() {
                var block = document.getElementById("partner-permissions");
                if (block) block.style.display = document.getElementById("role").value === "partner" ? "" : "none";
            }
            </script>';
    }

    /**
     * JSON for users.partner_permissions from the posted form.
     * Throws when "only certain events" is chosen without an event.
     */
    public static function fromPost(): string
    {
        $install = Permission::installFeatures();
        // Only features the installation allows can be chosen. A feature the installation
        // enables later is NOT granted automatically – the admin switches it on per partner.
        $features = array_values(array_intersect(array_map('strval', (array)($_POST['partner_features'] ?? [])), $install));
        $eventIds = null;
        if (!empty($_POST['partner_restrict_events'])) {
            $eventIds = array_values(array_filter(array_map('intval', (array)($_POST['partner_event_ids'] ?? []))));
            if (empty($eventIds)) {
                throw new \InvalidArgumentException('Bitte mindestens eine Veranstaltung auswählen oder „Nur bestimmte Veranstaltungen" ausschalten.');
            }
        }
        return Permission::encodeUserPermissions($features, $eventIds);
    }
}
