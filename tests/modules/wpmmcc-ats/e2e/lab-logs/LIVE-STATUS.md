# Lab Live Status

**Updated:** 2026-09-07T11:43:59+0800  
**Verdict:** RUNNING

## Processes

- **nightly:** no
- **matrix:** no
- **journey_wave:** yes (1)
- **journey_lane:** no
- **ct:** no
- **cross:** no

## Matrix (this wave)

- **Passed lanes:** 21/20
- `advanced-custom-fields-content, bbpress-content, core-content, directorist-content, easy-digital-downloads-content, elementor-content, envira-gallery-lite-content, events-manager-content…`
- **Failed lanes:** 1
- `learning-content`

## Journey wave (Stage 8)

- **Journey passed:** 11/20
- `advanced-custom-fields-content, bbpress-content, easy-digital-downloads-content, events-manager-content, hivepress-content, learnpress-content, lifterlms-content, the-events-calendar-content…`
- **Journey failed:** 0
- Status JSON: `/home/john/wpmmcc-ats3.0/tests/modules/wpmmcc-ats/e2e/lab-logs/JOURNEY-WAVE-STATUS.json`

## Nightly phases

- `2026-09-06T14:29:02+08:00 NIGHTLY_PHASE_PASSED name=manual_preflight secs=2`
- `2026-09-06T14:29:02+08:00 NIGHTLY_PHASE_PASSED name=ensure_automation_infra secs=0`
- `2026-09-06T14:29:03+08:00 NIGHTLY_PHASE_PASSED name=automation_preflight secs=1`
- `2026-09-06T14:30:38+08:00 NIGHTLY_PHASE_PASSED name=auto_content_surfaces secs=95`
- `2026-09-06T14:31:31+08:00 NIGHTLY_PHASE_PASSED name=auto_consistency secs=53`
- `2026-09-06T14:34:20+08:00 NIGHTLY_PHASE_PASSED name=auto_topology_time secs=169`
- `2026-09-06T15:20:50+08:00 NIGHTLY_PHASE_PASSED name=cross_worker secs=2790`
- `2026-09-06T15:20:57+08:00 NIGHTLY_PHASE_FAILED name=hotplug_give rc=1 secs=7`

## Open Task actions (parent must dispatch)

- **journey_failed:wp-recipe-maker-content** (journey_failed) — wp-recipe-maker-content
- **journey_wave_failed:?** (journey_wave_failed) — ?
- **lane_failed:bbpress-content** (lane_failed) — bbpress-content
- **lane_failed:woocommerce-content** (lane_failed) — woocommerce-content
- **lane_failed:easy-digital-downloads-content** (lane_failed) — easy-digital-downloads-content
- **lane_failed:tutor-content** (lane_failed) — tutor-content
- **lane_failed:the-events-calendar-content** (lane_failed) — the-events-calendar-content
- **lane_failed:core-content** (lane_failed) — core-content
- **lane_failed:learnpress-content** (lane_failed) — learnpress-content
- **lane_failed:wp-job-manager-content** (lane_failed) — wp-job-manager-content

## Recent errors / signals

- `2026-09-06T13:21:42+08:00 NIGHTLY_PHASE_FAILED name=auto_topology_time rc=1 secs=208`
- `2026-09-06T13:57:09+08:00 NIGHTLY_PHASE_FAILED name=arch_seam rc=1 secs=105`
- `2026-09-06T14:09:20+08:00 NIGHTLY_PHASE_FAILED name=ct_lane rc=1 secs=35`
- `2026-09-06T15:20:57+08:00 NIGHTLY_PHASE_FAILED name=hotplug_give rc=1 secs=7`
- `2026-09-06T16:06:41+08:00 SIGNAL_FRONTEND project=wptsall-content slot=shared stage=7p2 deep_verify_failed path=/en_us/`
- `2026-09-06T20:13:16+08:00 STAGE_FAILED project=tutor-content slot=shared stage=3 name=data-seed rc=1`
- `2026-09-06T20:13:17+08:00 RUN_FAILED project=tutor-content slot=shared exit=1 category=repo_regression surface=e2e_pipeline msg=Lane failed `
- `2026-09-07T01:33:06+08:00 RUN_FAILED project=learning-content slot=shared exit=1 category=repo_regression surface=e2e_pipeline msg=Lane fail`

## Stage failures

- `2026-09-03T11:24:28+08:00 STAGE_FAILED project=site-reviews-content slot=slot-h stage=3 name=data-seed rc=1`
- `2026-09-03T11:25:20+08:00 STAGE_FAILED project=give-content slot=slot-c stage=3 name=data-seed rc=1`
- `2026-09-03T11:26:05+08:00 STAGE_FAILED project=hivepress-content slot=slot-b stage=6 name=client-translate rc=1`
- `2026-09-05T19:23:22+08:00 STAGE_FAILED project=wp-job-manager-content slot=slot-g stage=6 name=client-translate rc=1`
- `2026-09-06T20:13:16+08:00 STAGE_FAILED project=tutor-content slot=shared stage=3 name=data-seed rc=1`

## Parent follow-up

1. **ACTION wake** → dispatch flat Task with `PENDING-ACTIONS` prompt
2. **mark done** → `lab-cli-mark-action-done.sh <key>`
3. **redo** → `lab-cli-redo-lane.sh <project> [slot]`
4. **journey redo** → `lab-cli-redo-lane.sh <project> [slot] --with-journeys`
5. **journey status** → `cat /home/john/wpmmcc-ats3.0/tests/modules/wpmmcc-ats/e2e/lab-logs/JOURNEY-WAVE-STATUS.json`
6. **PROGRESS wake** → update user briefly; no Task unless open_actions>0

See also: `/home/john/wpmmcc-ats3.0/tests/modules/wpmmcc-ats/e2e/lab-logs/PENDING-ACTIONS.json`, `/home/john/wpmmcc-ats3.0/tests/modules/wpmmcc-ats/e2e/lab-logs/agent-wake.log`
