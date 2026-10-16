import BigQueryPageController from '../bigquery/index_controller.js';

// Scheduled tasks page: after "Run due jobs now", reloads when the background
// run has started and finished, like the BigQuery sync page.
export default class extends BigQueryPageController {}
