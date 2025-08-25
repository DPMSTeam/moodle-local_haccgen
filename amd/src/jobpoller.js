/**
 * Poll the background job until it is done/failed.
 *
 * @module     local_aicourse/jobpoller
 * @copyright  …
 * @license    …
 */
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {

    /**
     * Start polling a job id.
     * @param {Number} jobid
     */
    const init = function(jobid) {

        const spinner = document.getElementById('aicourse-spinner');
        if (spinner) {
            spinner.classList.remove('hidden');
        }

        const poll = function() {
            Ajax.call([{
                methodname : 'local_aicourse_check_job',
                args       : { jobid: jobid }
            }])[0].then(function(data) {

                if (data.status === 'done') {
                    // Give Moodle a moment to build session data then reload.
                    window.setTimeout(function() { window.location.reload(); }, 1500);

                } else if (data.status === 'failed') {
                    Notification.alert(
                        'Generation failed',
                        data.errormsg || '',
                        'Close'
                    );
                } else if (data.status === 'missing') {
                    location.reload();
                } else {
                    setTimeout(poll, 5000);
                }

            }).catch(Notification.exception);
        };

        poll();
    };

    return /** @alias module:local_aicourse/jobpoller */ { init: init };
});
