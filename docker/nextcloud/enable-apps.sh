#!/bin/sh
# before-starting hook for the official nextcloud image: runs as www-data on
# every container start, after install/upgrade and before Apache. A previous
# attempt used `entrypoint: occ app:enable ...`, which replaces the image's
# entrypoint entirely and prevents Nextcloud from starting — hence this hook.
#
# app:enable is idempotent, so re-running on every start is harmless.
# openregister must be enabled first: keepiq builds on its AppHost engine.
#
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
set -eu

php /var/www/html/occ app:enable openregister
php /var/www/html/occ app:enable keepiq
