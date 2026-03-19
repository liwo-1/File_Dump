#!/bin/bash
# ========================================
# FileDump HTTP Integration Tests
# ========================================
#
# Run this on the Linux VM where Apache is serving FileDump.
# Usage:
#   bash tests/test_http.sh [base_url]
#
# Example:
#   bash tests/test_http.sh http://localhost
#   bash tests/test_http.sh https://files.example.com
#
# Prerequisites:
# - Apache must be running and serving FileDump
# - setup.php must have been run (admin user + main box exist)
# - curl must be installed
#
# What it tests:
# 1. .htaccess blocks access to data/, includes/, .db files
# 2. CSRF protection rejects forged form submissions
# 3. Full workflow: admin login → create box → box login → upload → download → delete
# 4. Auth checks (can't access pages without logging in)

BASE_URL="${1:-http://localhost}"
PASS=0
FAIL=0
COOKIE_JAR=$(mktemp)
COOKIE_JAR2=$(mktemp)
TEST_FILE=$(mktemp)

# Create a small test file for upload testing
echo "Hello from FileDump test!" > "$TEST_FILE"

# Colors for output (if terminal supports it)
RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

pass() {
    echo -e "  ${GREEN}PASS${NC}  $1"
    ((PASS++))
}

fail() {
    echo -e "  ${RED}FAIL${NC}  $1"
    [ -n "$2" ] && echo "        Detail: $2"
    ((FAIL++))
}

# Helper: extract CSRF token from a page's HTML
# The token is in a hidden input: <input type="hidden" name="csrf_token" value="...">
extract_csrf() {
    echo "$1" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1
}

echo ""
echo "========================================"
echo " FileDump HTTP Integration Tests"
echo " Base URL: $BASE_URL"
echo "========================================"
echo ""

# ========================================
# Test 1: .htaccess directory protection
# ========================================
echo "--- Directory Access Protection ---"

# data/ should be blocked
STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/data/")
if [ "$STATUS" = "403" ]; then
    pass "/data/ returns 403 Forbidden"
else
    fail "/data/ returned $STATUS (expected 403)"
fi

# data/uploads/ should be blocked
STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/data/uploads/")
if [ "$STATUS" = "403" ]; then
    pass "/data/uploads/ returns 403 Forbidden"
else
    fail "/data/uploads/ returned $STATUS (expected 403)"
fi

# includes/ should be blocked
STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/includes/config.php")
if [ "$STATUS" = "403" ]; then
    pass "/includes/config.php returns 403 Forbidden"
else
    fail "/includes/config.php returned $STATUS (expected 403)"
fi

# .db files should be blocked
STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/data/filedump.db")
if [ "$STATUS" = "403" ]; then
    pass "/data/filedump.db returns 403 Forbidden"
else
    fail "/data/filedump.db returned $STATUS (expected 403)"
fi

# .md files should be blocked
STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/spec.md")
if [ "$STATUS" = "403" ]; then
    pass "/spec.md returns 403 Forbidden"
else
    fail "/spec.md returned $STATUS (expected 403)"
fi

# setup.php should be blocked
STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/setup.php")
if [ "$STATUS" = "403" ]; then
    pass "/setup.php returns 403 Forbidden"
else
    fail "/setup.php returned $STATUS (expected 403)"
fi

# ========================================
# Test 2: Auth checks (unauthenticated access)
# ========================================
echo ""
echo "--- Authentication Checks ---"

# box.php should redirect to login when not authenticated
STATUS=$(curl -s -o /dev/null -w "%{http_code}" -L "$BASE_URL/box.php" -c "$COOKIE_JAR")
BODY=$(curl -s -L "$BASE_URL/box.php")
if echo "$BODY" | grep -q "Login\|box_name\|password"; then
    pass "box.php redirects to login when unauthenticated"
else
    fail "box.php accessible without authentication"
fi

# admin/dashboard.php should redirect to admin login
BODY=$(curl -s -L "$BASE_URL/admin/dashboard.php")
if echo "$BODY" | grep -q "Admin Login\|username\|password"; then
    pass "admin/dashboard.php redirects to login when unauthenticated"
else
    fail "admin/dashboard.php accessible without authentication"
fi

# api/download.php should reject without auth
STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/download.php?id=1")
if [ "$STATUS" = "403" ]; then
    pass "api/download.php returns 403 without auth"
else
    fail "api/download.php returned $STATUS (expected 403)"
fi

# ========================================
# Test 3: CSRF Protection
# ========================================
echo ""
echo "--- CSRF Protection ---"

# First, get a valid session by loading the login page
curl -s -c "$COOKIE_JAR" "$BASE_URL/index.php" > /dev/null

# Try to POST a login WITHOUT a CSRF token — should be rejected
BODY=$(curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "box_name=main&password=changeme" \
    "$BASE_URL/index.php")
if echo "$BODY" | grep -qi "invalid form submission\|try again"; then
    pass "Login rejects POST without CSRF token"
else
    fail "Login accepted POST without CSRF token"
fi

# Try with a FAKE CSRF token
BODY=$(curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "box_name=main&password=changeme&csrf_token=fake_token_12345" \
    "$BASE_URL/index.php")
if echo "$BODY" | grep -qi "invalid form submission\|try again"; then
    pass "Login rejects POST with fake CSRF token"
else
    fail "Login accepted POST with fake CSRF token"
fi

# Same test on admin login
curl -s -c "$COOKIE_JAR2" "$BASE_URL/admin/index.php" > /dev/null
BODY=$(curl -s -b "$COOKIE_JAR2" -c "$COOKIE_JAR2" \
    -d "username=admin&password=changeme" \
    "$BASE_URL/admin/index.php")
if echo "$BODY" | grep -qi "invalid form submission"; then
    pass "Admin login rejects POST without CSRF token"
else
    fail "Admin login accepted POST without CSRF token"
fi

# ========================================
# Test 4: Full Workflow — Admin creates box, user logs in, uploads, downloads, deletes
# ========================================
echo ""
echo "--- Full Workflow ---"

# Step 1: Admin login
ADMIN_JAR=$(mktemp)
PAGE=$(curl -s -c "$ADMIN_JAR" "$BASE_URL/admin/index.php")
CSRF=$(extract_csrf "$PAGE")

if [ -z "$CSRF" ]; then
    fail "Could not extract CSRF token from admin login page"
else
    # Login as admin (using default credentials from setup)
    RESPONSE=$(curl -s -b "$ADMIN_JAR" -c "$ADMIN_JAR" -L \
        -d "username=admin&password=changeme&csrf_token=$CSRF" \
        "$BASE_URL/admin/index.php")

    if echo "$RESPONSE" | grep -q "Admin\|dashboard\|All Boxes\|Create New Box"; then
        pass "Admin login successful"

        # Step 2: Create a test box
        CSRF=$(extract_csrf "$RESPONSE")
        CREATE_RESP=$(curl -s -b "$ADMIN_JAR" -c "$ADMIN_JAR" -L \
            -d "action=create_box&box_name=testbox&display_name=Test+Box&box_password=testpass123&csrf_token=$CSRF" \
            "$BASE_URL/admin/dashboard.php")

        if echo "$CREATE_RESP" | grep -q "testbox\|Test Box\|created"; then
            pass "Admin created test box 'testbox'"
        else
            fail "Could not create test box via admin"
        fi
    else
        fail "Admin login failed"
    fi
fi

# Step 3: Box login as the new test box
BOX_JAR=$(mktemp)
PAGE=$(curl -s -c "$BOX_JAR" "$BASE_URL/index.php")
CSRF=$(extract_csrf "$PAGE")

RESPONSE=$(curl -s -b "$BOX_JAR" -c "$BOX_JAR" -L \
    -d "box_name=testbox&password=testpass123&csrf_token=$CSRF" \
    "$BASE_URL/index.php")

if echo "$RESPONSE" | grep -q "Upload\|testbox\|Files"; then
    pass "Box login to 'testbox' successful"

    # Step 4: Upload a file
    CSRF=$(extract_csrf "$RESPONSE")
    UPLOAD_RESP=$(curl -s -b "$BOX_JAR" -c "$BOX_JAR" -L \
        -F "file=@$TEST_FILE;filename=test-upload.txt" \
        -F "csrf_token=$CSRF" \
        "$BASE_URL/api/upload.php")

    if echo "$UPLOAD_RESP" | grep -q "test-upload.txt"; then
        pass "File upload successful"

        # Step 5: Download the file
        # Extract the file ID from the page
        FILE_ID=$(echo "$UPLOAD_RESP" | grep -oP 'download\.php\?id=\K[0-9]+' | head -1)
        if [ -n "$FILE_ID" ]; then
            DL_CONTENT=$(curl -s -b "$BOX_JAR" "$BASE_URL/api/download.php?id=$FILE_ID")
            if echo "$DL_CONTENT" | grep -q "Hello from FileDump test"; then
                pass "File download returned correct content"
            else
                fail "Downloaded content doesn't match uploaded file"
            fi
        else
            fail "Could not extract file ID for download test"
        fi

        # Step 6: Delete the file
        CSRF=$(extract_csrf "$UPLOAD_RESP")
        DELETE_RESP=$(curl -s -b "$BOX_JAR" -c "$BOX_JAR" -L \
            -d "file_id=$FILE_ID&csrf_token=$CSRF" \
            "$BASE_URL/api/delete.php")

        if echo "$DELETE_RESP" | grep -q "deleted\|No files"; then
            pass "File deletion successful"
        else
            fail "File deletion may have failed"
        fi
    else
        fail "File upload failed"
    fi
else
    fail "Box login to 'testbox' failed"
fi

# Step 7: Clean up — delete the test box via admin
PAGE=$(curl -s -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/admin/dashboard.php")
CSRF=$(extract_csrf "$PAGE")
BOX_ID=$(echo "$PAGE" | grep -oP 'name="box_id" value="\K[0-9]+' | tail -1)

if [ -n "$BOX_ID" ] && [ -n "$CSRF" ]; then
    curl -s -b "$ADMIN_JAR" -c "$ADMIN_JAR" -L \
        -d "action=delete_box&box_id=$BOX_ID&csrf_token=$CSRF" \
        "$BASE_URL/admin/dashboard.php" > /dev/null
    pass "Cleanup: deleted test box"
fi

# ========================================
# Test 5: Security Headers
# ========================================
echo ""
echo "--- Security Headers ---"

HEADERS=$(curl -sI "$BASE_URL/index.php")

if echo "$HEADERS" | grep -qi "X-Content-Type-Options: nosniff"; then
    pass "X-Content-Type-Options: nosniff header present"
else
    fail "X-Content-Type-Options header missing"
fi

if echo "$HEADERS" | grep -qi "X-Frame-Options: DENY"; then
    pass "X-Frame-Options: DENY header present"
else
    fail "X-Frame-Options header missing"
fi

# ========================================
# Summary
# ========================================
echo ""
echo "========================================"
echo " Results: $PASS passed, $FAIL failed"
echo "========================================"
echo ""

# Cleanup temp files
rm -f "$COOKIE_JAR" "$COOKIE_JAR2" "$ADMIN_JAR" "$BOX_JAR" "$TEST_FILE"

exit $((FAIL > 0 ? 1 : 0))
