using System;
using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;
using UnityEngine.Networking;

namespace Gelato.Ar.Unity
{
    public sealed class UnityVisionModelArtifactFetcher : IVisionModelArtifactFetcher
    {
        public async Task<byte[]> FetchAsync(Uri artifactUri, long maximumBytes, CancellationToken cancellationToken)
        {
            if (artifactUri == null) throw new ArgumentNullException(nameof(artifactUri));
            if (!string.Equals(artifactUri.Scheme, Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("Vision model artifacts require HTTPS.");
            if (maximumBytes <= 0) throw new ArgumentOutOfRangeException(nameof(maximumBytes));

            using (var request = UnityWebRequest.Get(artifactUri))
            {
                var operation = request.SendWebRequest();
                while (!operation.isDone)
                {
                    cancellationToken.ThrowIfCancellationRequested();
                    if (request.downloadedBytes > (ulong)maximumBytes)
                    {
                        request.Abort();
                        throw new InvalidOperationException("Vision model artifact exceeded the configured download limit.");
                    }
                    await Task.Yield();
                }

#if UNITY_2020_2_OR_NEWER
                if (request.result != UnityWebRequest.Result.Success)
#else
                if (request.isNetworkError || request.isHttpError)
#endif
                    throw new InvalidOperationException("Vision model download failed: " + request.error);

                var data = request.downloadHandler != null ? request.downloadHandler.data : null;
                if (data == null || data.LongLength == 0)
                    throw new InvalidOperationException("Vision model download returned no bytes.");
                if (data.LongLength > maximumBytes)
                    throw new InvalidOperationException("Vision model artifact exceeded the configured download limit.");

                return data;
            }
        }
    }
}
