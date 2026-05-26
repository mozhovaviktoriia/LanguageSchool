# PowerShell script to remove secrets from git history

$projectPath = Get-Location
$oauthFile = "LanguageSchool/LanguageSchool/oauth_google.php"

# Create a backup
Write-Host "Creating backup..."
Copy-Item -Path $oauthFile -Destination "$oauthFile.backup" -Force

# Define the replacement function
$env:FILTER_BRANCH_SQUELCH_WARNING = 1

# Use git filter-branch to fix the commits
Write-Host "Filtering git history..."
&git filter-branch -f --tree-filter {
    if (Test-Path $oauthFile) {
        $content = Get-Content $oauthFile -Raw
        
        # Replace the hardcoded secrets
        $content = $content -replace `
            "define\('GOOGLE_CLIENT_ID',\s*'[^']*'\);",
            "define('GOOGLE_CLIENT_ID', `$_ENV['GOOGLE_CLIENT_ID'] ?? '');"
        
        $content = $content -replace `
            "define\('GOOGLE_CLIENT_SECRET',\s*'[^']*'\);",
            "define('GOOGLE_CLIENT_SECRET', `$_ENV['GOOGLE_CLIENT_SECRET'] ?? '');"
        
        Set-Content -Path $oauthFile -Value $content -NoNewline
    }
} -- --all

Write-Host "Done!"
