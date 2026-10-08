#!/bin/bash
# Sensei LMS CSV Import Script
#
# Usage: ./sensei-csv-import.sh [--path=/usr/local/var/www]
#
# This script imports courses and lessons from official Sensei sample data.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OFFICIAL_DATA="$(dirname "$SCRIPT_DIR")"
WP_PATH="${1:-/usr/local/var/www}"

# Copy CSV files to WordPress directory (Sensei requires relative paths)
cp "$OFFICIAL_DATA/sensei-lms/courses.csv" "$WP_PATH/sensei-courses.csv"
cp "$OFFICIAL_DATA/sensei-lms/lessons.csv" "$WP_PATH/sensei-lessons.csv"

echo "Sensei LMS Import"
echo "================="

# Count before
COURSES_BEFORE=$(cd "$WP_PATH" && wp eval 'echo wp_count_posts("course")->publish;' --user=admin 2>/dev/null | tail -1)
LESSONS_BEFORE=$(cd "$WP_PATH" && wp eval 'echo wp_count_posts("lesson")->publish;' --user=admin 2>/dev/null | tail -1)
echo "Before: $COURSES_BEFORE courses, $LESSONS_BEFORE lessons"

# Run import
cd "$WP_PATH" && wp sensei-import --user=admin --courses=sensei-courses.csv --lessons=sensei-lessons.csv 2>&1 | grep -v "Deprecated"

# Count after
COURSES_AFTER=$(cd "$WP_PATH" && wp eval 'echo wp_count_posts("course")->publish;' --user=admin 2>/dev/null | tail -1)
LESSONS_AFTER=$(cd "$WP_PATH" && wp eval 'echo wp_count_posts("lesson")->publish;' --user=admin 2>/dev/null | tail -1)
echo "After: $COURSES_AFTER courses, $LESSONS_AFTER lessons"

# Publish any draft courses
cd "$WP_PATH" && wp post list --post_type=course --post_status=draft --field=ID 2>/dev/null | while read -r ID; do
    if [ -n "$ID" ]; then
        wp post update "$ID" --post_status=publish 2>/dev/null
        echo "Published course ID: $ID"
    fi
done

# Cleanup temp files
rm -f "$WP_PATH/sensei-courses.csv" "$WP_PATH/sensei-lessons.csv"

echo ""
echo "Import complete!"
