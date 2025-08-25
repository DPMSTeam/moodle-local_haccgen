<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$jobid = required_param('id', PARAM_INT);

global $DB, $USER;

$job = $DB->get_record('local_aicourse_job', ['id' => $jobid], '*', MUST_EXIST);
require_capability('local/aicourse:manage', \context_course::instance($job->courseid));
if ($job->userid != $USER->id && !is_siteadmin()) {
    throw new moodle_exception('nopermissions', 'error');
}

$PAGE->set_url(new moodle_url('/local/aicourse/job.php', ['id' => $jobid]));
$PAGE->set_title(get_string('generatingcourse', 'local_aicourse'));
$PAGE->set_heading(get_string('generatingcourse', 'local_aicourse'));


echo $OUTPUT->header();
?>
<style>
  .aic-progress-wrap { margin-top: 1rem; }
  .aic-progress-label { display:flex; justify-content:space-between; align-items:center; margin-bottom:.5rem; }
  .aic-status { font-weight:600; text-transform:capitalize; }
  .progress { height: 24px; }
  .progress-bar { font-weight:600; }
  #aic-msg { white-space: pre-wrap; margin-top:.75rem; }
</style>

<div id="jobstatus" data-jobid="<?php echo (int)$jobid; ?>">
  <div class="aic-progress-wrap">
    <div class="aic-progress-label">
      <div>Status: <span id="aic-status" class="aic-status">queued</span></div>
      <div><span id="aic-percent">0</span>%</div>
    </div>
    <div class="progress" role="progressbar" aria-label="Job progress" aria-valuemin="0" aria-valuemax="100">
      <div id="aic-bar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%">0%</div>
    </div>
    <pre id="aic-msg"></pre>
  </div>

  <form id="continueform" method="post" action="<?php echo (new moodle_url('/local/aicourse/consume_job.php'))->out(false); ?>" style="display:none;">
    <input type="hidden" name="jobid" value="<?php echo (int)$jobid; ?>">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <button id="continuebtn" class="btn btn-primary mt-3">Continue</button>
  </form>
</div>

<script>
(function(){
  const jobid = document.getElementById('jobstatus').dataset.jobid;
  const statusEl = document.getElementById('aic-status');
  const percentEl = document.getElementById('aic-percent');
  const barEl = document.getElementById('aic-bar');
  const msgEl = document.getElementById('aic-msg');
  const formEl = document.getElementById('continueform');
  const continueBtn = document.getElementById('continuebtn');
  const url = '<?php echo (new moodle_url("/local/aicourse/job_status.php"))->out(false); ?>?id=' + encodeURIComponent(jobid);

  function setProgress(pct) {
    const n = Math.max(0, Math.min(100, parseInt(pct || 0, 10)));
    percentEl.textContent = n;
    barEl.style.width = n + '%';
    barEl.setAttribute('aria-valuenow', n);
    barEl.textContent = n + '%';
  }

  async function poll() {
    try {
      const res = await fetch(url, {credentials:'same-origin'});
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const j = await res.json();

      statusEl.textContent = j.status || 'queued';
      setProgress(j.progress || 0);
      msgEl.textContent = j.message || '';

      if (j.status === 'success') {
        formEl.style.display = 'block';
        // auto-continue; the button remains as a visible fallback
        formEl.submit();
        return;
      }
      if (j.status === 'error') {
        // show the Continue button so user can navigate onward if desired
        formEl.style.display = 'block';
        continueBtn.textContent = 'Continue';
        return;
      }
    } catch (e) {
      msgEl.textContent = 'Polling failed: ' + (e && e.message ? e.message : e);
    }
    setTimeout(poll, 1200);
  }


  poll();
})();
</script>

<?php echo $OUTPUT->footer();
