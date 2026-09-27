using Gelato.Ar.Core;
using UnityEngine;

namespace Gelato.Ar.Unity
{
    public sealed class PlayerPrefsTokenStore : IDeviceTokenStore
    {
        private const string Key = "gelato.ar.device_token";

        public string Load()
        {
            var value = PlayerPrefs.GetString(Key, string.Empty);
            return string.IsNullOrWhiteSpace(value) ? null : value;
        }

        public void Save(string token)
        {
            PlayerPrefs.SetString(Key, token);
            PlayerPrefs.Save();
        }

        public void Clear()
        {
            PlayerPrefs.DeleteKey(Key);
            PlayerPrefs.Save();
        }
    }
}
