#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "${SCRIPT_DIR}/config.sh"

FAMILY=""
SCOPE="${E2E_SCOPE:-core-only}"
HEADED=0

usage() {
  cat <<'EOF'
Usage:
  bash tests/modules/wpmmcc-ats/e2e/run-family-combo-lane.sh --family learning [--scope core-only|full] [--headed]
  bash tests/modules/wpmmcc-ats/e2e/run-family-combo-lane.sh --family content-meta [--scope core-only|full] [--headed]

Purpose:
  Run two independent plugin project lanes in one shared runtime so the family
  verifier can see combined evidence without being erased by the next deep reset.

Strategy:
  1. First project runs a normal full 7-stage lane.
  2. Second project overlays from Stage 3, so new seed/scan/relation/translate
     work is added without re-running Stage 2 cleanup.
  3. Family verifier is executed once against the combined runtime.

Examples:
  bash tests/modules/wpmmcc-ats/e2e/run-family-combo-lane.sh --family learning --scope core-only
  bash tests/modules/wpmmcc-ats/e2e/run-family-combo-lane.sh --family content-meta --scope core-only
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --family)
      FAMILY="${2:-}"
      shift 2
      ;;
    --scope)
      SCOPE="${2:-}"
      shift 2
      ;;
    --headed)
      HEADED=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1"
      usage
      exit 1
      ;;
  esac
done

if [[ -z "$FAMILY" ]]; then
  usage
  exit 1
fi

FIRST_PROJECT=""
SECOND_PROJECT=""
FAMILY_PROJECT=""
VERIFY_SCRIPT=""
FAMILY_LABEL=""
POST_COMBO_SQL=""
POST_COMBO_SQL_2=""

case "$FAMILY" in
  learning)
    FIRST_PROJECT="tutor-content"
    SECOND_PROJECT="learnpress-content"
    FAMILY_PROJECT="learning-content"
    VERIFY_SCRIPT="${E2E_DIR}/php/verify-learning-content.php"
    FAMILY_LABEL="Tutor + LearnPress"
    POST_COMBO_SQL="SELECT src.post_type, pm.target_site_id, COUNT(*) AS cnt FROM wp_wptsall_post_mappings pm INNER JOIN wp_posts src ON src.ID = pm.source_post_id WHERE src.post_type IN ('courses','lesson','tutor_quiz','lp_course','lp_lesson','lp_quiz') GROUP BY src.post_type, pm.target_site_id ORDER BY src.post_type, pm.target_site_id;"
    POST_COMBO_SQL_2="SELECT source_taxonomy, target_site_id, COUNT(*) AS cnt FROM wp_wptsall_term_mappings WHERE source_taxonomy IN ('course-category','course-tag','course_category','course_tag') GROUP BY source_taxonomy, target_site_id ORDER BY source_taxonomy, target_site_id;"
    ;;
  content-meta)
    FIRST_PROJECT="seriously-simple-podcasting-content"
    SECOND_PROJECT="wp-recipe-maker-content"
    FAMILY_PROJECT="content-meta-content"
    VERIFY_SCRIPT="${E2E_DIR}/php/verify-content-meta-content.php"
    FAMILY_LABEL="Seriously Simple Podcasting + WP Recipe Maker"
    POST_COMBO_SQL="SELECT src.post_type, pm.target_site_id, COUNT(*) AS cnt FROM wp_wptsall_post_mappings pm INNER JOIN wp_posts src ON src.ID = pm.source_post_id WHERE src.post_type IN ('podcast','wprm_recipe') GROUP BY src.post_type, pm.target_site_id ORDER BY src.post_type, pm.target_site_id;"
    POST_COMBO_SQL_2="SELECT source_taxonomy, target_site_id, COUNT(*) AS cnt FROM wp_wptsall_term_mappings WHERE source_taxonomy IN ('series','wprm_course','wprm_cuisine','wprm_keyword','wprm_ingredient') GROUP BY source_taxonomy, target_site_id ORDER BY source_taxonomy, target_site_id;"
    ;;
  *)
    abort "Unsupported family: ${FAMILY}. Supported: learning, content-meta"
    ;;
esac

if [[ ! -f "$VERIFY_SCRIPT" ]]; then
  abort "Missing family verifier: ${VERIFY_SCRIPT}"
fi

RUN_ARGS=()
if [[ "$HEADED" -eq 1 ]]; then
  RUN_ARGS+=(--headed)
fi

print_stage "FAMILY" "Family Combo Lane: ${FAMILY}"
START_TS=$(stage_start_time)

info "Family: ${FAMILY_LABEL}"
info "Scope: ${SCOPE}"
info "Run order: ${FIRST_PROJECT} -> ${SECOND_PROJECT} -> ${FAMILY_PROJECT} verifier"
echo ""

info "Step 1/3: full lane for ${FIRST_PROJECT}"
E2E_PROJECT="${FIRST_PROJECT}" E2E_SCOPE="${SCOPE}" bash "${SCRIPT_DIR}/run.sh" "${RUN_ARGS[@]}"

echo ""
info "Step 2/3: overlay lane for ${SECOND_PROJECT} from Stage 3 (skip Stage 2 cleanup)"
E2E_PROJECT="${SECOND_PROJECT}" E2E_SCOPE="${SCOPE}" bash "${SCRIPT_DIR}/run.sh" --stage 3 "${RUN_ARGS[@]}"

echo ""
info "Step 3/3: running combined family verifier ${FAMILY_PROJECT}"
# Lab: use wp_eval/wp_cli (docker exec). Host /var/www/wordpress is not present.
E2E_PROJECT="${FAMILY_PROJECT}" E2E_SCOPE="${SCOPE}" wp_eval "${VERIFY_SCRIPT}"

echo ""
info "Combined mapping snapshot:"
wp_cli db query "${POST_COMBO_SQL}" || true
echo ""
wp_cli db query "${POST_COMBO_SQL_2}" || true

stage_elapsed "${START_TS}"
ok "Family combo lane passed: ${FAMILY}"
