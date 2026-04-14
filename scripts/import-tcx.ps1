param(
    [Parameter(Mandatory = $true)]
    [string]$FolderPath,

    [Parameter(Mandatory = $false)]
    [string]$ApiUrl = "http://localhost:8000/api/workouts/import"
)

$ErrorActionPreference = "Stop"

function Get-TcxSummaryFromXml {
    param(
        [Parameter(Mandatory = $true)]
        [string]$XmlContent
    )

    $xml = [xml]$XmlContent
    $ns = New-Object System.Xml.XmlNamespaceManager($xml.NameTable)
    $ns.AddNamespace("tcx", "http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2")

    $idNode = $xml.SelectSingleNode("//tcx:Activity/tcx:Id", $ns)
    if (-not $idNode -or [string]::IsNullOrWhiteSpace($idNode.InnerText)) {
        throw "Missing Activity/Id in TCX."
    }
    $startTimeIso = $idNode.InnerText.Trim()

    $lapNodes = $xml.SelectNodes("//tcx:Lap", $ns)
    if (-not $lapNodes -or $lapNodes.Count -eq 0) {
        throw "No Lap elements found in TCX."
    }

    [double]$totalDurationSec = 0
    [double]$totalDistanceM = 0

    foreach ($lap in $lapNodes) {
        $timeNode = $lap.SelectSingleNode("./tcx:TotalTimeSeconds", $ns)
        $distanceNode = $lap.SelectSingleNode("./tcx:DistanceMeters", $ns)
        if (-not $timeNode -or -not $distanceNode) {
            throw "Lap is missing TotalTimeSeconds or DistanceMeters."
        }

        $totalDurationSec += [double]::Parse($timeNode.InnerText.Trim(), [System.Globalization.CultureInfo]::InvariantCulture)
        $totalDistanceM += [double]::Parse($distanceNode.InnerText.Trim(), [System.Globalization.CultureInfo]::InvariantCulture)
    }

    return @{
        startTimeIso = $startTimeIso
        durationSec = [int][Math]::Round($totalDurationSec)
        distanceM = [int][Math]::Round($totalDistanceM)
    }
}

if (-not (Test-Path -LiteralPath $FolderPath)) {
    Write-Error "Folder does not exist: $FolderPath"
    exit 1
}

$tcxFiles = Get-ChildItem -LiteralPath $FolderPath -Filter "*.tcx" -File | Sort-Object Name

if ($tcxFiles.Count -eq 0) {
    Write-Host "No .tcx files found in: $FolderPath"
    Write-Host "Summary: imported=0, errors=0, total=0"
    exit 0
}

$importedCount = 0
$errorCount = 0

foreach ($file in $tcxFiles) {
    try {
        $rawTcxXml = Get-Content -LiteralPath $file.FullName -Raw -Encoding UTF8
        $summary = Get-TcxSummaryFromXml -XmlContent $rawTcxXml

        $payload = [ordered]@{
            source = "tcx"
            sourceActivityId = [System.IO.Path]::GetFileNameWithoutExtension($file.Name)
            startTimeIso = $summary.startTimeIso
            durationSec = $summary.durationSec
            distanceM = $summary.distanceM
            rawTcxXml = $rawTcxXml
        }
        $body = $payload | ConvertTo-Json -Depth 10

        $response = Invoke-RestMethod -Uri $ApiUrl -Method Post -ContentType "application/json" -Body $body

        $id = $response.id
        $status = "UNKNOWN"

        if ($response.PSObject.Properties.Name -contains "updated" -and $response.updated -eq $true) {
            $status = "UPDATED"
        } elseif ($response.PSObject.Properties.Name -contains "created") {
            if ($response.created -eq $true) {
                $status = "CREATED"
            } elseif ($response.created -eq $false) {
                $status = "DEDUPED"
            }
        }

        $importedCount++
        Write-Host ("[{0}] id={1} status={2}" -f $file.Name, $id, $status)
    } catch {
        $errorCount++
        $message = $_.Exception.Message

        # For Invoke-RestMethod, server response body is usually available in ErrorDetails.Message
        if ($_.ErrorDetails -and -not [string]::IsNullOrWhiteSpace($_.ErrorDetails.Message)) {
            $message = "$message | response=$($_.ErrorDetails.Message)"
        } elseif ($_.Exception.Response -and $_.Exception.Response.PSObject.Properties.Name -contains "Content") {
            # Fallback for some PowerShell/runtime variants
            $content = $_.Exception.Response.Content
            if (-not [string]::IsNullOrWhiteSpace($content)) {
                $message = "$message | response=$content"
            }
        }

        Write-Host ("[{0}] id=- status=ERROR error={1}" -f $file.Name, $message)
    }
}

$total = $tcxFiles.Count
Write-Host ""
Write-Host ("Summary: imported={0}, errors={1}, total={2}" -f $importedCount, $errorCount, $total)
