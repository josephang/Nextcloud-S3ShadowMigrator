#!/bin/bash
#
# S3ShadowMigrator - Full Drive Drain Script
# Safely migrates ALL local user files to B2 in rolling batches.
# SAFE: upload always happens before database update and local delete.
# If anything crashes, local files are untouched. Can be re-run safely.
#
# Usage:
#   bash drain_all.sh              # Default: 2000 small + 50 large per round
#   bash drain_all.sh 5000 100     # Custom: 5000 small + 100 large per round
#

OCC_DIR="/service/Nimbus/california/www/nextcloud"
SMALL_BATCH="${1:-2000}"        # Files per round for small files (< 50 MB each)
LARGE_BATCH="${2:-50}"          # Files per round for large files (50 MB+)
SMALL_MAX_SIZE=52428800         # 50 MB threshold in bytes
SLEEP_BETWEEN=1                 # Seconds between rounds
LOG="/tmp/s3_drain_$(date +%Y%m%d_%H%M%S).log"

echo "=====================================" | tee -a "$LOG"
echo "S3 Shadow Migrator - Full Drive Drain" | tee -a "$LOG"
echo "Started: $(date)" | tee -a "$LOG"
echo "Small file batch: $SMALL_BATCH files (< 50 MB each)" | tee -a "$LOG"
echo "Large file batch: $LARGE_BATCH files (>= 50 MB each)" | tee -a "$LOG"
echo "Log: $LOG" | tee -a "$LOG"
echo "=====================================" | tee -a "$LOG"

TOTAL=0
ROUND=0

# ---- PHASE 1: Drain all small files (<= 50MB) in large batches ----
echo "" | tee -a "$LOG"
echo "=== PHASE 1: Small files (<= 50 MB) ===" | tee -a "$LOG"
while true; do
    ROUND=$((ROUND + 1))
    TS=$(date '+%H:%M:%S')

    # Run the command and stream output live to the console, while saving it to a temp file for grep
    sudo -u www-data php $OCC_DIR/occ s3shadowmigrator:migrate-file \
        --batch="$SMALL_BATCH" \
        --max-size="$SMALL_MAX_SIZE" \
        2>&1 | tee /tmp/drain_current_small.out

    STATUS=${PIPESTATUS[0]}
    
    OUT=$(cat /tmp/drain_current_small.out)

    if [ "$STATUS" -ne 0 ]; then
        echo "[$TS] FATAL: occ command failed with status $STATUS. Aborting drain to prevent infinite loop." | tee -a "$LOG"
        exit 1
    fi

    MIGRATED=$(echo "$OUT" | grep -oP 'Batch complete: \K\d+' || echo "0")
    TOTAL=$((TOTAL + MIGRATED))

    echo "[$TS] Round $ROUND complete. Session total: $TOTAL" | tee -a "$LOG"

    if echo "$OUT" | grep -qE "Batch complete: 0|no local files found"; then
        echo "Phase 1 complete." | tee -a "$LOG"
        break
    fi
    sleep "$SLEEP_BETWEEN"
done

# ---- PHASE 2: Drain remaining large files one batch at a time ----
echo "" | tee -a "$LOG"
echo "=== PHASE 2: Large files (> 50 MB) ===" | tee -a "$LOG"
while true; do
    ROUND=$((ROUND + 1))
    TS=$(date '+%H:%M:%S')

    # Run the command and stream output live to the console, while saving it to a temp file for grep
    sudo -u www-data php $OCC_DIR/occ s3shadowmigrator:migrate-file \
        --batch="$LARGE_BATCH" \
        2>&1 | tee /tmp/drain_current_large.out

    STATUS=${PIPESTATUS[0]}
    
    OUT=$(cat /tmp/drain_current_large.out)

    if [ "$STATUS" -ne 0 ]; then
        echo "[$TS] FATAL: occ command failed with status $STATUS. Aborting drain to prevent infinite loop." | tee -a "$LOG"
        exit 1
    fi

    MIGRATED=$(echo "$OUT" | grep -oP 'Batch complete: \K\d+' || echo "0")
    TOTAL=$((TOTAL + MIGRATED))

    echo "[$TS] Round $ROUND complete. Session total: $TOTAL" | tee -a "$LOG"

    if echo "$OUT" | grep -qE "Batch complete: 0|no local files found"; then
        echo "Phase 2 complete." | tee -a "$LOG"
        break
    fi
    sleep 3  # Extra pause between large file batches
done

echo "" | tee -a "$LOG"
echo "=====================================" | tee -a "$LOG"
echo "FULL DRAIN COMPLETE: $(date)" | tee -a "$LOG"
echo "Total files migrated: $TOTAL" | tee -a "$LOG"
echo "=====================================" | tee -a "$LOG"
