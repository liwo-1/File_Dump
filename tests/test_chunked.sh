#!/bin/bash
# ========================================
# Test: Chunked Upload API
# ========================================
# Tests the init → chunk → complete flow using curl.

BASE_URL="${1:-http://localhost/filedump}"
JAR=$(mktemp)
PASS=0
FAIL=0

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

pass() { echo -e "  ${GREEN}PASS${NC}  $1"; ((PASS++)); }
fail() { echo -e "  ${RED}FAIL${NC}  $1"; [ -n "$2" ] && echo "        Detail: $2"; ((FAIL++)); }

echo ""
echo "========================================"
echo " Chunked Upload API Tests"
echo " Base URL: $BASE_URL"
echo "========================================"
echo ""

# --- Login to main box ---
PAGE=$(curl -s -c "$JAR" "$BASE_URL/index.php")
CSRF=$(echo "$PAGE" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)

curl -s -b "$JAR" -c "$JAR" -L \
    -d "box_name=main&password=test&csrf_token=$CSRF" \
    "$BASE_URL/index.php" > /dev/null

# Get fresh CSRF token from box page
BOX_PAGE=$(curl -s -b "$JAR" -c "$JAR" "$BASE_URL/box.php")
CSRF=$(echo "$BOX_PAGE" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)

if [ -z "$CSRF" ]; then
    fail "Could not login or get CSRF token"
    exit 1
fi
pass "Logged into main box, got CSRF token"

# --- Create a 25MB test file (3 chunks at 10MB each) ---
echo "  ...creating 25MB test file"
TEST_FILE=$(mktemp)
dd if=/dev/urandom of="$TEST_FILE" bs=1M count=25 2>/dev/null
FILE_SIZE=$(stat -c%s "$TEST_FILE")
TOTAL_CHUNKS=3
echo "  ...file size: $FILE_SIZE bytes, chunks: $TOTAL_CHUNKS"

# --- Test: Init upload ---
echo ""
echo "--- Init ---"
INIT_RESP=$(curl -s -b "$JAR" -c "$JAR" \
    -H "X-CSRF-Token: $CSRF" \
    -d "action=init&file_name=testfile.bin&file_size=$FILE_SIZE&total_chunks=$TOTAL_CHUNKS&mime_type=application/octet-stream" \
    "$BASE_URL/api/upload-chunk.php?action=init")

UPLOAD_ID=$(echo "$INIT_RESP" | grep -oP '"upload_id"\s*:\s*"\K[^"]+')

if [ -n "$UPLOAD_ID" ]; then
    pass "Init returned upload_id: $UPLOAD_ID"
else
    fail "Init failed" "$INIT_RESP"
    rm -f "$TEST_FILE" "$JAR"
    exit 1
fi

# --- Test: Status (should be empty) ---
STATUS_RESP=$(curl -s -b "$JAR" "$BASE_URL/api/upload-chunk.php?action=status&upload_id=$UPLOAD_ID")
if echo "$STATUS_RESP" | grep -q '"uploaded_chunks":\[\]'; then
    pass "Status shows no chunks uploaded yet"
else
    fail "Status response unexpected" "$STATUS_RESP"
fi

# --- Test: Upload chunks ---
echo ""
echo "--- Chunks ---"
CHUNK_SIZE=10485760  # 10MB

for i in 0 1 2; do
    OFFSET=$((i * CHUNK_SIZE))
    if [ $i -eq 2 ]; then
        # Last chunk may be smaller
        BYTES_LEFT=$((FILE_SIZE - OFFSET))
    else
        BYTES_LEFT=$CHUNK_SIZE
    fi

    CHUNK_FILE=$(mktemp)
    dd if="$TEST_FILE" of="$CHUNK_FILE" bs=1 skip=$OFFSET count=$BYTES_LEFT 2>/dev/null

    CHUNK_RESP=$(curl -s -b "$JAR" -c "$JAR" \
        -H "X-CSRF-Token: $CSRF" \
        -F "action=chunk" \
        -F "upload_id=$UPLOAD_ID" \
        -F "chunk_index=$i" \
        -F "chunk=@$CHUNK_FILE" \
        "$BASE_URL/api/upload-chunk.php?action=chunk")

    if echo "$CHUNK_RESP" | grep -q '"success":true'; then
        pass "Chunk $i uploaded"
    else
        fail "Chunk $i failed" "$CHUNK_RESP"
    fi

    rm -f "$CHUNK_FILE"
done

# --- Test: Status (should show all 3) ---
STATUS_RESP=$(curl -s -b "$JAR" "$BASE_URL/api/upload-chunk.php?action=status&upload_id=$UPLOAD_ID")
if echo "$STATUS_RESP" | grep -q '"uploaded_chunks":\[0,1,2\]'; then
    pass "Status shows all 3 chunks uploaded"
else
    fail "Status doesn't show all chunks" "$STATUS_RESP"
fi

# --- Test: Complete ---
echo ""
echo "--- Complete ---"
COMPLETE_RESP=$(curl -s -b "$JAR" -c "$JAR" \
    -H "X-CSRF-Token: $CSRF" \
    -d "action=complete&upload_id=$UPLOAD_ID" \
    "$BASE_URL/api/upload-chunk.php?action=complete")

if echo "$COMPLETE_RESP" | grep -q '"success":true'; then
    FILE_ID=$(echo "$COMPLETE_RESP" | grep -oP '"file_id"\s*:\s*\K[0-9]+')
    pass "Assembly complete, file_id: $FILE_ID"
else
    fail "Assembly failed" "$COMPLETE_RESP"
fi

# --- Test: Verify file appears in box page ---
BOX_PAGE=$(curl -s -b "$JAR" "$BASE_URL/box.php")
if echo "$BOX_PAGE" | grep -q "testfile.bin"; then
    pass "File appears in box file list"
else
    fail "File not found in box page"
fi

# --- Test: Download and verify size ---
if [ -n "$FILE_ID" ]; then
    DL_FILE=$(mktemp)
    curl -s -b "$JAR" "$BASE_URL/api/download.php?id=$FILE_ID" -o "$DL_FILE"
    DL_SIZE=$(stat -c%s "$DL_FILE")

    if [ "$DL_SIZE" -eq "$FILE_SIZE" ]; then
        pass "Downloaded file size matches ($DL_SIZE bytes)"
    else
        fail "Size mismatch: uploaded $FILE_SIZE, downloaded $DL_SIZE"
    fi

    # Verify content matches (MD5)
    ORIG_MD5=$(md5sum "$TEST_FILE" | cut -d' ' -f1)
    DL_MD5=$(md5sum "$DL_FILE" | cut -d' ' -f1)
    if [ "$ORIG_MD5" = "$DL_MD5" ]; then
        pass "Downloaded file MD5 matches original"
    else
        fail "MD5 mismatch: original=$ORIG_MD5, downloaded=$DL_MD5"
    fi

    rm -f "$DL_FILE"
fi

# --- Cleanup: delete the test file via API ---
if [ -n "$FILE_ID" ]; then
    CSRF2=$(echo "$BOX_PAGE" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
    curl -s -b "$JAR" -c "$JAR" -L \
        -d "file_id=$FILE_ID&csrf_token=$CSRF2" \
        "$BASE_URL/api/delete.php" > /dev/null
    pass "Cleanup: deleted test file"
fi

# --- Summary ---
echo ""
echo "========================================"
echo " Results: $PASS passed, $FAIL failed"
echo "========================================"
echo ""

rm -f "$TEST_FILE" "$JAR"
exit $((FAIL > 0 ? 1 : 0))
