#!/bin/bash
# RMS Connection Verification Script
# Date: 2026-08-28
# Purpose: Verify all file connections after reorganization

echo "=========================================="
echo "RMS Project Connection Verification"
echo "=========================================="
echo ""

# Check critical files exist
echo "1. Checking critical files..."
files=(
    "index.php"
    "login.php"
    "logout.php"
    "about.php"
    "contact.php"
    "features.php"
    "research-archive.php"
)

all_exist=true
for file in "${files[@]}"; do
    if [ -f "$file" ]; then
        echo "   ✓ $file exists"
    else
        echo "   ✗ $file MISSING"
        all_exist=false
    fi
done
echo ""

# Check new folder structure
echo "2. Checking new folder structure..."
folders=(
    "database/schema"
    "database/migrations"
    "config"
    "includes"
    "pages"
    "css"
    "uploads"
    "docs"
)

for folder in "${folders[@]}"; do
    if [ -d "$folder" ]; then
        echo "   ✓ $folder/ exists"
    else
        echo "   ✗ $folder/ MISSING"
        all_exist=false
    fi
done
echo ""

# Check database files moved correctly
echo "3. Checking database files..."
if [ -f "database/schema/rms_db.sql" ]; then
    echo "   ✓ database/schema/rms_db.sql"
else
    echo "   ✗ database/schema/rms_db.sql MISSING"
    all_exist=false
fi

if [ -f "database/migrations/rms_db_migration.sql" ]; then
    echo "   ✓ database/migrations/rms_db_migration.sql"
else
    echo "   ✗ database/migrations/rms_db_migration.sql MISSING"
    all_exist=false
fi

if [ -f "database/migrations/add_contact_messages_table.sql" ]; then
    echo "   ✓ database/migrations/add_contact_messages_table.sql"
else
    echo "   ✗ database/migrations/add_contact_messages_table.sql MISSING"
    all_exist=false
fi
echo ""

# Check config files moved correctly
echo "4. Checking configuration files..."
if [ -f "config/settings.json" ]; then
    echo "   ✓ config/settings.json"
else
    echo "   ✗ config/settings.json MISSING"
    all_exist=false
fi

if [ -f "config/skills-lock.json" ]; then
    echo "   ✓ config/skills-lock.json"
else
    echo "   ✗ config/skills-lock.json MISSING"
    all_exist=false
fi
echo ""

# Check includes directory
echo "5. Checking includes files..."
includes_files=(
    "includes/auth.php"
    "includes/config.php"
    "includes/contact-handler.php"
    "includes/module-pages.php"
)

for file in "${includes_files[@]}"; do
    if [ -f "$file" ]; then
        echo "   ✓ $file"
    else
        echo "   ✗ $file MISSING"
        all_exist=false
    fi
done
echo ""

# Check pages directory
echo "6. Checking pages directory..."
page_count=$(find pages -name "*.php" -type f | wc -l)
echo "   ✓ Found $page_count PHP files in pages/"
echo ""

# Check CSS directory
echo "7. Checking CSS files..."
css_files=(
    "css/style.css"
    "css/about.css"
    "css/tokens.css"
    "css/tokens.php"
)

for file in "${css_files[@]}"; do
    if [ -f "$file" ]; then
        echo "   ✓ $file"
    else
        echo "   ✗ $file MISSING"
        all_exist=false
    fi
done
echo ""

# Check for broken includes
echo "8. Checking for potential broken includes..."
broken=0

# Check if any PHP files still reference old paths
if grep -r "migrations/" *.php 2>/dev/null | grep -v "database/migrations" > /dev/null; then
    echo "   ⚠ Found references to old 'migrations/' path"
    broken=1
fi

if grep -r "rms_db.sql" *.php pages/*.php includes/*.php 2>/dev/null | grep -v "database/schema" > /dev/null; then
    echo "   ⚠ Found references to old 'rms_db.sql' path"
    broken=1
fi

if [ $broken -eq 0 ]; then
    echo "   ✓ No broken path references found"
fi
echo ""

# Summary
echo "=========================================="
if [ "$all_exist" = true ]; then
    echo "✓ ALL CHECKS PASSED"
    echo "Project structure is clean and organized!"
else
    echo "✗ SOME CHECKS FAILED"
    echo "Please review missing files above."
fi
echo "=========================================="
