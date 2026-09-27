using System.Text;
using Gelato.Ar.Core;
using UnityEngine;

namespace Gelato.Ar.Unity
{
    public sealed class DesktopSimulatorDebugOverlay : MonoBehaviour
    {
        [SerializeField] private GelatoArBootstrap bootstrap;
        [SerializeField] private DesktopSimulatorController simulator;
        [SerializeField] private bool visible = true;

        private GUIStyle _boxStyle;
        private GUIStyle _textStyle;

        private void OnGUI()
        {
            if (!visible || bootstrap == null || simulator == null) return;

            EnsureStyles();
            var coordinator = bootstrap.Coordinator;

            GUILayout.BeginArea(new Rect(16f, 16f, 500f, 360f), _boxStyle);
            GUILayout.Label("GELATO AIR3 DESKTOP SIMULATOR", _textStyle);

            if (coordinator == null)
            {
                GUILayout.Label("Initializing...", _textStyle);
                GUILayout.EndArea();
                return;
            }

            GUILayout.Label("State: " + coordinator.State, _textStyle);
            GUILayout.Label("Tracking: " + (coordinator.Platform is DesktopSimulatorPlatform p && p.Tracking ? "ON" : "OFF"), _textStyle);
            GUILayout.Space(8f);
            GUILayout.Label("P Pair   F1 Work   F2 Start   V Validate   E Expo   R Reset", _textStyle);
            GUILayout.Label("1-9 ingredient   Shift+1-9 low confidence   U unexpected", _textStyle);
            GUILayout.Label("C confirm Verify   X resolve unexpected", _textStyle);
            GUILayout.Space(8f);

            var builder = new StringBuilder();
            foreach (var line in simulator.Log) builder.AppendLine(line);
            GUILayout.Label(builder.ToString(), _textStyle);
            GUILayout.EndArea();
        }

        private void EnsureStyles()
        {
            if (_boxStyle != null) return;

            _boxStyle = new GUIStyle(GUI.skin.box)
            {
                alignment = TextAnchor.UpperLeft,
                padding = new RectOffset(12, 12, 12, 12)
            };
            _textStyle = new GUIStyle(GUI.skin.label)
            {
                fontSize = 14,
                wordWrap = true
            };
        }
    }
}
