import BigQueryPageController from '../bigquery/index_controller.js';

// Reporting database page: the same behavior as the BigQuery sync page (a
// confirmation only for newly selected private views, and a reload when a
// running sync finishes), plus the connection form's fields for the chosen
// database type. Without JavaScript every field stays visible and the server
// still requires the confirmation.
export default class extends BigQueryPageController {
    static targets = ['type', 'serverFields', 'sqlsrvFields'];

    connect() {
        super.connect();
        this.showType();
    }

    showType() {
        const selected = this.typeTargets.find((input) => input.checked);
        if (!selected) return;
        this.serverFieldsTargets.forEach((element) => {
            element.hidden = selected.value === 'sqlite';
        });
        this.sqlsrvFieldsTargets.forEach((element) => {
            element.hidden = selected.value !== 'sqlsrv';
        });
    }
}
