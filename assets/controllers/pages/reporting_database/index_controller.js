import BigQueryPageController from '../bigquery/index_controller.js';

// Reporting database page: the same behavior as the BigQuery sync page. It
// asks for confirmation only when a private view is newly selected, and
// reloads the status table when a running sync finishes. Without JavaScript
// the server still requires the confirmation.
export default class extends BigQueryPageController {}
