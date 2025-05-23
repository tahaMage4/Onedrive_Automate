<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OneDrive Connection Status</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1><i class="fab fa-microsoft"></i> OneDrive Connection Status</h1>

        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <!-- Connection Status Card -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-link"></i> Connection Status</h5>
            </div>
            <div class="card-body">
                @if ($isConnected)
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <strong>Connected to OneDrive</strong>
                        @if ($hasRefreshToken)
                            <p class="mb-0 mt-2">Refresh token is available for automatic reconnection.</p>
                        @endif
                    </div>

                    <form action="{{ route('onedrive.logout') }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-sign-out-alt"></i> Disconnect
                        </button>
                    </form>
                @else
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Not connected to OneDrive</strong>
                    </div>

                    <a href="{{ route('onedrive.login') }}" class="btn btn-primary">
                        <i class="fab fa-microsoft"></i> Connect to OneDrive
                    </a>
                @endif
            </div>
        </div>

        @if ($isConnected)
            <!-- Flash Folders Status Card -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-folder"></i> Flash Folders Status</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        @foreach ($flashFolderData as $folderName => $folderData)
                            <div class="col-md-6 mb-3">
                                <div class="card h-100">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h6 class="mb-0">{{ $folderName }}</h6>
                                        @if (isset($folderData['error']))
                                            <span class="badge bg-danger">Error</span>
                                        @else
                                            <span class="badge bg-success">{{ count($folderData) }} items</span>
                                        @endif
                                    </div>
                                    <div class="card-body">
                                        @if (isset($folderData['error']))
                                            <div class="text-danger">
                                                <i class="fas fa-exclamation-circle"></i>
                                                {{ $folderData['error'] }}
                                            </div>
                                        @else
                                            <div class="small">
                                                @php
                                                    $fileCount = collect($folderData)->where('type', 'file')->count();
                                                    $folderCount = collect($folderData)->where('type', 'folder')->count();
                                                @endphp
                                                <i class="fas fa-file"></i> {{ $fileCount }} files<br>
                                                <i class="fas fa-folder"></i> {{ $folderCount }} folders
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Sync Files Card -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-sync"></i> Sync Files</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('onedrive.sync') }}" method="POST">
                        @csrf
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="option" class="form-label">Sync Option</label>
                                    <select class="form-select" id="option" name="option">
                                        <option value="flashfiles">Flash Files Only (.fls files)</option>
                                        <option value="folders">All Flash Folders</option>
                                    </select>
                                    <div class="form-text">Choose what to sync from OneDrive</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="local_path" class="form-label">Local Storage Path</label>
                                    <input type="text" class="form-control" id="local_path" name="local_path" value="onedrive" placeholder="e.g., flashfiles">
                                    <div class="form-text">Relative to storage/app directory</div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-download"></i> Sync Files Now
                        </button>
                    </form>
                </div>
            </div>

            <!-- Flash Folder Contents -->
            @if (!empty($flashFolderData))
                @foreach ($flashFolderData as $folderName => $folderData)
                    @if (!isset($folderData['error']) && !empty($folderData))
                        <div class="card mb-4">
                            <div class="card-header bg-secondary text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-folder-open"></i> {{ $folderName }} Contents
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th><i class="fas fa-file"></i> Name</th>
                                                <th><i class="fas fa-tag"></i> Type</th>
                                                <th><i class="fas fa-weight-hanging"></i> Size</th>
                                                <th><i class="fas fa-clock"></i> Last Modified</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse ($folderData as $file)
                                                <tr>
                                                    <td>
                                                        @if ($file['type'] === 'file')
                                                            <i class="fas fa-file text-primary"></i>
                                                        @else
                                                            <i class="fas fa-folder text-warning"></i>
                                                        @endif
                                                        {{ $file['name'] }}
                                                    </td>
                                                    <td>
                                                        @if ($file['type'] === 'file')
                                                            <span class="badge bg-primary">File</span>
                                                        @else
                                                            <span class="badge bg-warning">Folder</span>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if ($file['type'] === 'file' && isset($file['size']))
                                                            @if ($file['size'] > 1048576)
                                                                {{ round($file['size'] / 1048576, 2) }} MB
                                                            @elseif ($file['size'] > 1024)
                                                                {{ round($file['size'] / 1024, 2) }} KB
                                                            @else
                                                                {{ $file['size'] }} bytes
                                                            @endif
                                                        @elseif ($file['type'] === 'folder' && isset($file['childCount']))
                                                            {{ $file['childCount'] }} items
                                                        @else
                                                            -
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if (isset($file['lastModified']))
                                                            {{ \Carbon\Carbon::parse($file['lastModified'])->format('M d, Y H:i') }}
                                                        @else
                                                            -
                                                        @endif
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">
                                                        <i class="fas fa-folder-open"></i> No files found
                                                    </td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
            @endif
        @endif

        <!-- Help Card -->
        <div class="card">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fas fa-question-circle"></i> Help</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-info-circle text-info"></i> Supported URLs</h6>
                        <ul class="small">
                            <li>SharePoint sharing URLs</li>
                            <li>OneDrive for Business folders</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-cog text-secondary"></i> File Types</h6>
                        <ul class="small">
                            <li>Flash files (.fls extension)</li>
                            <li>All file types in selected folders</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>