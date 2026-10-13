-- Equivalent monitoring activity for both modules on the perf project (@PID / @DT set by caller).
-- New module: every text_validation form of event_1 instance 1 is SDV complete with 2 verified fields; 2000 queries.
-- Old module: 2000 DRW query threads on text_validation_monstat (what Monitoring QR writes).

SET @E1 = (select e.event_id from redcap_events_metadata e join redcap_events_arms a on a.arm_id=e.arm_id
           where a.project_id=@PID and a.arm_num=1 and e.descrip='Event 1');
SET @UID = (select ui_id from redcap_user_information where username='test_user4');

SET @sql = CONCAT("INSERT IGNORE INTO sdv_form (project_id, record, event_id, instrument, instance, status, created_at, updated_by, updated_at, completed_by, completed_at)
  SELECT project_id, record, event_id, 'text_validation', coalesce(instance,1), 'complete', now(), 'test_user4', now(), 'test_user4', now()
  FROM ", @DT, " WHERE project_id=", @PID, " AND field_name='text_validation_complete'");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = CONCAT("INSERT IGNORE INTO sdv_field (project_id, record, event_id, instance, field_name, instrument, status, value_hash, verified_by, verified_at)
  SELECT project_id, record, event_id, coalesce(instance,1), field_name, 'text_validation', 'verified', sha2(value, 256), 'test_user4', now()
  FROM ", @DT, " WHERE project_id=", @PID, " AND field_name in ('ptname_v2','email_v2')");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO sdv_query (project_id, record, event_id, instrument, instance, field_name, status, raised_by, raised_at, updated_at)
  SELECT project_id, record, event_id, 'text_validation', 1, 'email_v2', IF(record like '%0', 'open', 'closed'), 'test_user4', now(), now()
  FROM sdv_form WHERE project_id=@PID AND event_id=@E1 AND instrument='text_validation' AND instance=1 AND record < 'P02000';
INSERT INTO sdv_query_msg (query_id, action, response_code, text, username, ts)
  SELECT query_id, 'raise', NULL, 'Please check against source', 'test_user4', now() FROM sdv_query WHERE project_id=@PID;

INSERT INTO redcap_data_quality_status (non_rule, project_id, record, event_id, field_name, instance, status, exclude, query_status)
  SELECT 1, project_id, record, event_id, 'text_validation_monstat', 1, NULL, 0, IF(record like '%0', 'OPEN', 'CLOSED')
  FROM sdv_form WHERE project_id=@PID AND event_id=@E1 AND instrument='text_validation' AND instance=1 AND record < 'P02000';
INSERT INTO redcap_data_quality_resolutions (status_id, ts, user_id, response_requested, comment, current_query_status, field_comment_edited)
  SELECT status_id, now(), @UID, 1, '[{"field":"email_v2","field_label":"Email","flags":"@ENDPOINT-SECONDARY","query":"Please check against source"}]', query_status, 0
  FROM redcap_data_quality_status WHERE project_id=@PID;
