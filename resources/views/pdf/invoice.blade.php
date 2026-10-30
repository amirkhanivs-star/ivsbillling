@php
  if (!isset($currencyGroups) && isset($i) && is_object($i)) {
    $builderData = app(\App\Services\ConsolidatedInvoiceBuilder::class)->buildForInvoice($i, auth()->user(), true);
    $mode = $builderData['mode'];
    $family = $builderData['family'];
    $familyStudents = $builderData['familyStudents'];
    $parentDisplay = $builderData['parentDisplay'];
    $currencyGroups = $builderData['currencyGroups'];
    $totalItemsCount = $builderData['totalItemsCount'];
    $dept = $builderData['dept'];
    $branding = $builderData['branding'];
    $banner = $builderData['banner'];
    $statementNumber = $builderData['statementNumber'];
    $generatedAt = $builderData['generatedAt'];
    $issuedOn = $builderData['issuedOn'];
    $dueDate = $builderData['dueDate'];
    $referenceInvoice = $builderData['referenceInvoice'];
  }
@endphp
@include('pdf.consolidated-challan')
