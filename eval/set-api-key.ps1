# Asks for an Anthropic API key without showing it on screen and saves it to eval/.anthropic-key
# (git-ignored), where the eval runner reads it. The key never goes into the chat or the repo.
$secure = Read-Host -AsSecureString "Anthropic API anahtarini yapistir ve Enter'a bas"
$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try { $key = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr).Trim() }
finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }

if (-not $key.StartsWith('sk-ant-')) {
    Write-Host "Bu bir Anthropic API anahtarina benzemiyor (sk-ant- ile baslamali). Kaydedilmedi."
    exit 1
}

[IO.File]::WriteAllText((Join-Path $PSScriptRoot '.anthropic-key'), $key)
Write-Host "Anahtar kaydedildi. Bu sekmeyi kapatabilirsin."
