<#
  TV Doutor CRM - Script de Deploy Automático
  Faz upload dos arquivos diretamente via FTP para o servidor HostGator
  
  Uso:
    .\deploy.ps1              # Deploy completo
    .\deploy.ps1 -SkipZip     # Só sobe arquivos (sem regerar nada)
#>

param(
    [switch]$SkipZip
)

$ErrorActionPreference = "Stop"
$rootDir = Split-Path -Parent $MyInvocation.MyCommand.Path

# --- Ler configuração ---
$configFile = Join-Path $rootDir ".deploy-config"
if (-not (Test-Path $configFile)) {
    Write-Host "`n  ERRO: Arquivo .deploy-config nao encontrado!" -ForegroundColor Red
    Write-Host "  Copie .deploy-config.example para .deploy-config e preencha suas credenciais.`n" -ForegroundColor Yellow
    exit 1
}

$config = @{}
Get-Content $configFile | ForEach-Object {
    $line = $_.Trim()
    if ($line -and -not $line.StartsWith("#")) {
        $parts = $line -split "=", 2
        if ($parts.Length -eq 2) {
            $config[$parts[0].Trim()] = $parts[1].Trim()
        }
    }
}

$ftpHost    = $config["FTP_HOST"]
$ftpUser    = $config["FTP_USER"]
$ftpPass    = $config["FTP_PASS"]
$ftpPort    = $config["FTP_PORT"]
$remotePath = $config["REMOTE_PATH"]

if (-not $ftpHost -or -not $ftpUser -or -not $ftpPass) {
    Write-Host "`n  ERRO: FTP_HOST, FTP_USER e FTP_PASS sao obrigatorios no .deploy-config`n" -ForegroundColor Red
    exit 1
}
if (-not $ftpPort) { $ftpPort = "21" }
if (-not $remotePath) { $remotePath = "/" }
$remotePath = $remotePath.TrimEnd("/")

$cred = New-Object System.Net.NetworkCredential($ftpUser, $ftpPass)

Write-Host ""
Write-Host "  ========================================" -ForegroundColor Cyan
Write-Host "    TV Doutor CRM - Deploy via FTP" -ForegroundColor Cyan
Write-Host "  ========================================" -ForegroundColor Cyan
Write-Host "  Servidor: $ftpHost" -ForegroundColor Gray
Write-Host "  Caminho:  $remotePath/" -ForegroundColor Gray
Write-Host ""

# --- Funções FTP ---
function FTP-EnsureDir($ftpBase, $dirPath) {
    $parts = $dirPath -split "[/\\]" | Where-Object { $_ }
    $current = $ftpBase
    foreach ($p in $parts) {
        $current = "$current/$p"
        $uri = "ftp://${ftpHost}:${ftpPort}${current}/"
        try {
            $req = [System.Net.FtpWebRequest]::Create($uri)
            $req.Method = [System.Net.WebRequestMethods+Ftp]::ListDirectory
            $req.Credentials = $cred
            $req.UseBinary = $true
            $req.UsePassive = $true
            $req.KeepAlive = $false
            $resp = $req.GetResponse()
            $resp.Close()
        } catch {
            try {
                $mkReq = [System.Net.FtpWebRequest]::Create($uri)
                $mkReq.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
                $mkReq.Credentials = $cred
                $mkReq.UseBinary = $true
                $mkReq.UsePassive = $true
                $mkReq.KeepAlive = $false
                $mkResp = $mkReq.GetResponse()
                $mkResp.Close()
            } catch {}
        }
    }
}

function FTP-UploadFile($localPath, $remoteFilePath) {
    $uri = "ftp://${ftpHost}:${ftpPort}${remoteFilePath}"
    $fileBytes = [System.IO.File]::ReadAllBytes($localPath)
    
    $req = [System.Net.FtpWebRequest]::Create($uri)
    $req.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
    $req.Credentials = $cred
    $req.UseBinary = $true
    $req.UsePassive = $true
    $req.KeepAlive = $false
    $req.ContentLength = $fileBytes.Length

    $stream = $req.GetRequestStream()
    $stream.Write($fileBytes, 0, $fileBytes.Length)
    $stream.Close()

    $resp = $req.GetResponse()
    $resp.Close()
}

# --- Coletar arquivos para deploy ---
$deployDirs = @("config", "includes", "pages")
$deployFiles = @("index.php")

$allFiles = @()

foreach ($dir in $deployDirs) {
    $dirFull = Join-Path $rootDir $dir
    if (Test-Path $dirFull) {
        Get-ChildItem -Path $dirFull -Recurse -File | ForEach-Object {
            $rel = $_.FullName.Substring($rootDir.Length).Replace("\", "/")
            $allFiles += @{ Local = $_.FullName; Remote = "$remotePath$rel" ; Rel = $rel }
        }
    }
}

foreach ($f in $deployFiles) {
    $full = Join-Path $rootDir $f
    if (Test-Path $full) {
        $allFiles += @{ Local = $full; Remote = "$remotePath/$f"; Rel = "/$f" }
    }
}

$totalFiles = $allFiles.Count
Write-Host "  Arquivos para deploy: $totalFiles" -ForegroundColor Yellow
Write-Host ""

# --- Criar diretórios remotos ---
Write-Host "  [1/2] Criando diretorios remotos..." -ForegroundColor Yellow

$remoteDirs = @()
foreach ($f in $allFiles) {
    $dirPart = [System.IO.Path]::GetDirectoryName($f.Remote).Replace("\", "/")
    $relDir = $dirPart.Substring($remotePath.Length)
    if ($relDir -and $relDir -ne "/" -and $remoteDirs -notcontains $relDir) {
        $remoteDirs += $relDir
    }
}

$remoteDirs = $remoteDirs | Sort-Object
foreach ($d in $remoteDirs) {
    FTP-EnsureDir $remotePath $d
}
Write-Host "  OK - $($remoteDirs.Count) diretorios verificados" -ForegroundColor Green

# --- Upload dos arquivos ---
Write-Host "  [2/2] Fazendo upload dos arquivos..." -ForegroundColor Yellow

$uploaded = 0
$failed = 0
$errors = @()
$startTime = Get-Date

foreach ($f in $allFiles) {
    $uploaded++
    $pct = [math]::Round(($uploaded / $totalFiles) * 100)
    Write-Host "`r  [$pct%] $uploaded/$totalFiles - $($f.Rel)" -NoNewline -ForegroundColor Gray
    
    try {
        FTP-UploadFile $f.Local $f.Remote
    } catch {
        $failed++
        $errors += "$($f.Rel): $($_.Exception.Message)"
    }
}

$elapsed = [math]::Round(((Get-Date) - $startTime).TotalSeconds, 1)
Write-Host ""

if ($failed -gt 0) {
    Write-Host ""
    Write-Host "  AVISOS ($failed erros):" -ForegroundColor Yellow
    foreach ($e in $errors) {
        Write-Host "    - $e" -ForegroundColor Red
    }
}

# --- Limpar script de extração se existir ---
try {
    $delUri = "ftp://${ftpHost}:${ftpPort}${remotePath}/_deploy_extract.php"
    $delReq = [System.Net.FtpWebRequest]::Create($delUri)
    $delReq.Method = [System.Net.WebRequestMethods+Ftp]::DeleteFile
    $delReq.Credentials = $cred
    $delReq.UsePassive = $true
    $delReq.KeepAlive = $false
    $delResp = $delReq.GetResponse()
    $delResp.Close()
} catch {}

try {
    $delUri2 = "ftp://${ftpHost}:${ftpPort}${remotePath}/TVDCRM_upload.zip"
    $delReq2 = [System.Net.FtpWebRequest]::Create($delUri2)
    $delReq2.Method = [System.Net.WebRequestMethods+Ftp]::DeleteFile
    $delReq2.Credentials = $cred
    $delReq2.UsePassive = $true
    $delReq2.KeepAlive = $false
    $delResp2 = $delReq2.GetResponse()
    $delResp2.Close()
} catch {}

# --- Finalizado ---
$success = $totalFiles - $failed
Write-Host ""
Write-Host "  ========================================" -ForegroundColor Green
Write-Host "    Deploy concluido!" -ForegroundColor Green
Write-Host "  ========================================" -ForegroundColor Green
Write-Host "  Arquivos: $success/$totalFiles enviados ($elapsed s)" -ForegroundColor Gray
Write-Host "  URL: https://crm.tvdoutor.com.br" -ForegroundColor Gray
Write-Host ""
