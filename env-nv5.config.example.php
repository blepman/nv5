<?php
declare(strict_types=1);

/**
 * Eksempel — kopier til env/env-nv5/config.php på serveren (ved siden av www/).
 * Denne fila ligger i Git som mal; den faktiske config.php skal ikke committes.
 */
return [
    'NV5_ADMIN_USER' => 'admin',
    'NV5_ADMIN_PASSWORD' => 'sett-et-sterkt-passord',
    // 'NV5_SYNC_SERVER_KEY' => 'valgfri-nøkkel-for-?sync=server',
];
