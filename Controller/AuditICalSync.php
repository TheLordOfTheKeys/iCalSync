<?php
/**
 * This file is part of ICalSync plugin for FacturaScripts
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\ICalSync\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\ICalSync\Model\ICalSyncLog;

/**
 * Audit report controller for iCalSync.
 *
 * Shows sync statistics: total syncs, success rate, error rate,
 * per-entity breakdown, date range filter, and CSV export.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class AuditICalSync extends Controller
{
    /** @var array Estadísticas generales */
    public array $stats = [];

    /** @var array Registros detallados para la tabla */
    public array $logEntries = [];

    /** @var string Fecha inicio del filtro */
    public string $filterDateFrom = '';

    /** @var string Fecha fin del filtro */
    public string $filterDateTo = '';

    /** @var string Filtro por tipo de operación */
    public string $filterOperation = '';

    /** @var string Filtro por estado */
    public string $filterStatus = '';

    /** @var int Total de registros */
    public int $totalCount = 0;

    /** @var int Página actual */
    public int $page = 1;

    /** @var int Registros por página */
    public int $limit = 50;

    /**
     * Returns basic page attributes.
     *
     * @return array
     */
    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'admin';
        $pageData['title'] = 'icalsync-audit-report';
        $pageData['icon'] = 'fa-solid fa-chart-bar';
        $pageData['showonmenu'] = true;
        return $pageData;
    }

    public function run(): void
    {
        parent::run();

        // Handle export action
        $actionName = $this->request->get('action', '');
        if ('export-csv' === $actionName) {
            $this->exportCsvAction();
            return;
        }

        // Load filters from request
        $this->loadFilters();

        // Compute statistics
        $this->computeStats();

        // Load paginated log entries
        $this->loadLogEntries();
    }

    /**
     * Load filter values from request.
     */
    private function loadFilters(): void
    {
        $this->filterDateFrom = $this->request->get('datefrom', '');
        $this->filterDateTo = $this->request->get('dateto', '');
        $this->filterOperation = $this->request->get('operation', '');
        $this->filterStatus = $this->request->get('status', '');
        $this->page = max(1, (int)$this->request->get('page', 1));

        // Default to last 30 days if no date range
        if (empty($this->filterDateFrom) && empty($this->filterDateTo)) {
            $this->filterDateTo = date('Y-m-d');
            $this->filterDateFrom = date('Y-m-d', strtotime('-30 days'));
        }

        $customLimit = (int)$this->request->get('limit', 0);
        if ($customLimit > 0) {
            $this->limit = min(200, $customLimit);
        }
    }

    /**
     * Compute aggregate statistics from log entries.
     */
    private function computeStats(): void
    {
        $logModel = new ICalSyncLog();
        $where = $this->buildWhere();

        // Get all matching records for stats
        $allEntries = $logModel->all($where, [], 0, 0);

        $total = count($allEntries);
        $successCount = 0;
        $errorCount = 0;
        $conflictCount = 0;
        $skippedCount = 0;
        $byOperation = [];
        $byEntity = [];
        $byStatus = [];

        foreach ($allEntries as $entry) {
            switch ($entry->status) {
                case 'success':
                    $successCount++;
                    break;
                case 'error':
                    $errorCount++;
                    break;
                case 'conflict':
                    $conflictCount++;
                    break;
                case 'skipped':
                    $skippedCount++;
                    break;
            }

            $op = $entry->operation ?? 'unknown';
            $byOperation[$op] = ($byOperation[$op] ?? 0) + 1;

            $entity = $entry->entity_type ?? 'unknown';
            $byEntity[$entity] = ($byEntity[$entity] ?? 0) + 1;

            $status = $entry->status ?? 'unknown';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        $this->stats = [
            'total' => $total,
            'success' => $successCount,
            'error' => $errorCount,
            'conflict' => $conflictCount,
            'skipped' => $skippedCount,
            'success_rate' => $total > 0 ? round(($successCount / $total) * 100, 1) : 0,
            'error_rate' => $total > 0 ? round(($errorCount / $total) * 100, 1) : 0,
            'by_operation' => $byOperation,
            'by_entity' => $byEntity,
            'by_status' => $byStatus,
        ];

        $this->totalCount = $total;
    }

    /**
     * Load paginated log entries for the detail table.
     */
    private function loadLogEntries(): void
    {
        $logModel = new ICalSyncLog();
        $where = $this->buildWhere();
        $offset = ($this->page - 1) * $this->limit;

        $this->logEntries = $logModel->all($where, ['created_at' => 'DESC'], $this->limit, $offset);
    }

    /**
     * Build where clause from filters.
     *
     * @return DataBaseWhere[]
     */
    private function buildWhere(): array
    {
        $where = [];

        if (!empty($this->filterDateFrom)) {
            $where[] = new DataBaseWhere('created_at', $this->filterDateFrom . ' 00:00:00', '>=');
        }
        if (!empty($this->filterDateTo)) {
            $where[] = new DataBaseWhere('created_at', $this->filterDateTo . ' 23:59:59', '<=');
        }
        if (!empty($this->filterOperation)) {
            $where[] = new DataBaseWhere('operation', $this->filterOperation);
        }
        if (!empty($this->filterStatus)) {
            $where[] = new DataBaseWhere('status', $this->filterStatus);
        }

        return $where;
    }

    /**
     * Export filtered results as CSV.
     */
    private function exportCsvAction(): void
    {
        $logModel = new ICalSyncLog();
        $where = $this->buildWhere();
        $entries = $logModel->all($where, ['created_at' => 'DESC'], 0, 0);

        // Build CSV
        $csv = "id,created_at,operation,status,entity_type,entity_id,message,details\n";
        foreach ($entries as $entry) {
            $csv .= implode(',', [
                    $entry->id ?? '',
                    $entry->created_at ?? '',
                    $entry->operation ?? '',
                    $entry->status ?? '',
                    $entry->entity_type ?? '',
                    $entry->entity_id ?? '',
                    '"' . str_replace('"', '""', $entry->message ?? '') . '"',
                    '"' . str_replace('"', '""', $entry->details ?? '') . '"',
                ]) . "\n";
        }

        // Send CSV as download
        $this->setTemplate(false);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="icalsync-audit-' . date('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF" . $csv;
        exit();
    }

    /**
     * Get available operations for filter dropdown.
     *
     * @return array
     */
    public function getOperationOptions(): array
    {
        return [
            '' => '--- All ---',
            'sync-starting' => 'Sync Starting',
            'sync-completed' => 'Sync Completed',
            'sync-build-failed' => 'Build Failed',
            'sync-update-error' => 'Update Error',
            'sync-create-error' => 'Create Error',
            'sync-recurring-skipped' => 'Recurring Skipped',
            'conflict-detected' => 'Conflict Detected',
            'conflict-resolved' => 'Conflict Resolved',
            'email-sent' => 'Email Sent',
            'email-send-error' => 'Email Error',
            'cita-imported' => 'Cita Imported',
            'cita-exported' => 'Cita Exported',
            'cita-import-error' => 'Cita Import Error',
            'cita-export-error' => 'Cita Export Error',
            'cita-sync-error' => 'Cita Sync Error',
            'event-exported' => 'Event Exported',
            'event-export-error' => 'Event Export Error',
            'event-deleted-from-icloud' => 'Event Deleted from iCloud',
            'event-delete-error' => 'Event Delete Error',
        ];
    }

    /**
     * Get available statuses for filter dropdown.
     *
     * @return array
     */
    public function getStatusOptions(): array
    {
        return [
            '' => '--- All ---',
            'success' => 'Success',
            'error' => 'Error',
            'conflict' => 'Conflict',
            'skipped' => 'Skipped',
        ];
    }
}
