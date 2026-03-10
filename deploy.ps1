<#
  TV Doutor CRM - Script de Deploy Automático
  Faz upload dos arquivos via FTP para o servidor HostGator
  
  Uso:
    .\deploy.ps1              # Deploy completo (gera zip, upload, extrai)
    .\deploy.ps1 -SkipZip     # Só faz upload do zip existente
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

$ftpHost   = $config["FTP_HOST"]
$ftpUser   = $config["FTP_USER"]
$ftpPass   = $config["FTP_PASS"]
$ftpPort   = $config["FTP_PORT"]
$remotePath = $config["REMOTE_PATH"]
$deployKey = $config["DEPLOY_KEY"]

if (-not $ftpHost -or -not $ftpUser -or -not $ftpPass) {
    Write-Host "`n  ERRO: FTP_HOST, FTP_USER e FTP_PASS sao obrigatorios no .deploy-config`n" -ForegroundColor Red
    exit 1
}
if (-not $ftpPort) { $ftpPort = "21" }
if (-not $remotePath) { $remotePath = "/home2/tvdout68/crm.tvdoutor.com.br" }

Write-Host ""
Write-Host "  ========================================" -ForegroundColor Cyan
Write-Host "    TV Doutor CRM - Deploy" -ForegroundColor Cyan
Write-Host "  ========================================" -ForegroundColor Cyan
Write-Host "  Servidor: $ftpHost" -ForegroundColor Gray
Write-Host "  Caminho:  $remotePath" -ForegroundColor Gray
Write-Host ""

# --- 1. Gerar ZIP ---
$zipPath = Join-Path $rootDir "TVDCRM_upload.zip"

if (-not $SkipZip) {
    Write-Host "  [1/4] Gerando TVDCRM_upload.zip..." -ForegroundColor Yellow
    if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
    Compress-Archive -Path (
        (Join-Path $rootDir "config\*"),
        (Join-Path $rootDir "includes\*"),
        (Join-Path $rootDir "pages\*"),
        (Join-Path $rootDir "index.php")
    ) -DestinationPath $zipPath -Force
    $zipSize = [math]::Round((Get-Item $zipPath).Length / 1KB, 1)
    Write-Host "  OK - ZIP gerado ($zipSize KB)" -ForegroundColor Green
} else {
    if (-not (Test-Path $zipPath)) {
        Write-Host "  ERRO: TVDCRM_upload.zip nao encontrado!" -ForegroundColor Red
        exit 1
    }
    Write-Host "  [1/4] Usando ZIP existente (--SkipZip)" -ForegroundColor Yellow
}

# --- 2. Upload do ZIP via FTP ---
Write-Host "  [2/4] Fazendo upload via FTP..." -ForegroundColor Yellow

$ftpUri = "ftp://${ftpHost}:${ftpPort}${remotePath}/TVDCRM_upload.zip"
$fileBytes = [System.IO.File]::ReadAllBytes($zipPath)

try {
    $ftpRequest = [System.Net.FtpWebRequest]::Create($ftpUri)
    $ftpRequest.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
    $ftpRequest.Credentials = New-Object System.Net.NetworkCredential($ftpUser, $ftpPass)
    $ftpRequest.UseBinary = $true
    $ftpRequest.UsePassive = $true
    $ftpRequest.KeepAlive = $false
    $ftpRequest.ContentLength = $fileBytes.Length

    $stream = $ftpRequest.GetRequestStream()
    $stream.Write($fileBytes, 0, $fileBytes.Length)
    $stream.Close()

    $response = $ftpRequest.GetResponse()
    Write-Host "  OK - Upload concluido ($($response.StatusDescription.Trim()))" -ForegroundColor Green
    $response.Close()
} catch {
    Write-Host "  ERRO no upload FTP: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}

# --- 3. Upload do script de extração ---
Write-Host "  [3/4] Enviando script de extracao..." -ForegroundColor Yellow

$extractPhp = @"
<?php
// Script temporário de deploy - auto-remove após execução
if (($_GET['key'] ?? '') !== '$deployKey') { http_response_code(403); die('Forbidden'); }

`$zip = new ZipArchive();
`$zipFile = __DIR__ . '/TVDCRM_upload.zip';

if (!file_exists(`$zipFile)) { die(json_encode(['error' => 'ZIP not found'])); }

if (`$zip->open(`$zipFile) === true) {
    `$zip->extractTo(__DIR__);
    `$zip->close();
    unlink(`$zipFile);
    unlink(__FILE__);
    echo json_encode(['success' => true, 'message' => 'Deploy concluido', 'time' => date('Y-m-d H:i:s')]);
} else {
    echo json_encode(['error' => 'Failed to extract ZIP']);
}
"@

$extractBytes = [System.Text.Encoding]::UTF8.GetBytes($extractPhp)
$extractUri = "ftp://${ftpHost}:${ftpPort}${remotePath}/_deploy_extract.php"

try {
    $ftpRequest2 = [System.Net.FtpWebRequest]::Create($extractUri)
    $ftpRequest2.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
    $ftpRequest2.Credentials = New-Object System.Net.NetworkCredential($ftpUser, $ftpPass)
    $ftpRequest2.UseBinary = $true
    $ftpRequest2.UsePassive = $true
    $ftpRequest2.KeepAlive = $false
    $ftpRequest2.ContentLength = $extractBytes.Length

    $stream2 = $ftpRequest2.GetRequestStream()
    $stream2.Write($extractBytes, 0, $extractBytes.Length)
    $stream2.Close()

    $response2 = $ftpRequest2.GetResponse()
    Write-Host "  OK - Script de extracao enviado" -ForegroundColor Green
    $response2.Close()
} catch {
    Write-Host "  ERRO ao enviar script de extracao: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}

# --- 4. Executar extração via HTTP ---
Write-Host "  [4/4] Extraindo arquivos no servidor..." -ForegroundColor Yellow

$extractUrl = "https://crm.tvdoutor.com.br/_deploy_extract.php?key=$deployKey"

try {
    Start-Sleep -Seconds 2
    $webResponse = Invoke-RestMethod -Uri $extractUrl -Method GET -TimeoutSec 30

    if ($webResponse.success) {
        Write-Host "  OK - $($webResponse.message) ($($webResponse.time))" -ForegroundColor Green
    } else {
        Write-Host "  AVISO: Resposta inesperada: $($webResponse | ConvertTo-Json)" -ForegroundColor Yellow
    }
} catch {
    Write-Host "  ERRO na extracao: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "  Tente acessar manualmente: $extractUrl" -ForegroundColor Yellow
    exit 1
}

# --- Finalizado ---
Write-Host ""
Write-Host "  ========================================" -ForegroundColor Green
Write-Host "    Deploy concluido com sucesso!" -ForegroundColor Green
Write-Host "  ========================================" -ForegroundColor Green
Write-Host "  URL: https://crm.tvdoutor.com.br" -ForegroundColor Gray
Write-Host ""
