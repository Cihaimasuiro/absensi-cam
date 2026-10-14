use std::sync::mpsc::{channel, Receiver};
use eframe::egui::{self, Color32, FontId, Rect as EguiRect, RichText, Vec2};
use launcher_core::{ApiClient, MockApiClient};
use serde::Deserialize;
use wry::{
    dpi::{LogicalPosition, LogicalSize},
    http::Request,
    Rect, WebView, WebViewBuilder,
};

const INDEX_HTML: &str = include_str!("index.html");

#[derive(Debug, Deserialize)]
struct IpcPayload {
    cmd: String,
    id: Option<String>,
}

enum AppIpcMsg {
    ListDevices,
    Approve(String),
    Reject(String),
}

struct WebViewSpikeApp {
    client: MockApiClient,
    webview: WebView,
    ipc_rx: Receiver<AppIpcMsg>,
    last_bounds: Option<EguiRect>,
    egui_menu_choice: String,
}

impl WebViewSpikeApp {
    fn new(cc: &eframe::CreationContext<'_>) -> Self {
        let (ipc_tx, ipc_rx) = channel::<AppIpcMsg>();

        let client = MockApiClient::new();
        let initial_devices = client.list_devices().unwrap_or_default();
        let initial_json = serde_json::to_string(&initial_devices).unwrap_or_default();

        let tx_clone = ipc_tx.clone();
        let webview = WebViewBuilder::new()
            .with_html(INDEX_HTML)
            .with_devtools(cfg!(debug_assertions))
            .with_ipc_handler(move |req: Request<String>| {
                let body = req.body();
                match serde_json::from_str::<IpcPayload>(body) {
                    Ok(payload) => match payload.cmd.as_str() {
                        "list_devices" => {
                            let _ = tx_clone.send(AppIpcMsg::ListDevices);
                        }
                        "approve" => {
                            if let Some(id) = payload.id {
                                let _ = tx_clone.send(AppIpcMsg::Approve(id));
                            }
                        }
                        "reject" => {
                            if let Some(id) = payload.id {
                                let _ = tx_clone.send(AppIpcMsg::Reject(id));
                            }
                        }
                        unknown => {
                            eprintln!("[IPC] DITOLAK: Perintah tidak dikenal: '{}'", unknown);
                        }
                    },
                    Err(err) => {
                        eprintln!("[IPC] DITOLAK: Format JSON tidak valid ({}): {}", err, body);
                    }
                }
            })
            .build_as_child(cc)
            .expect("Gagal menginisialisasi wry child webview di dalam eframe");

        // Initial device state push after creation
        let init_script = format!("window.renderDevices({});", initial_json);
        let _ = webview.evaluate_script(&init_script);

        Self {
            client,
            webview,
            ipc_rx,
            last_bounds: None,
            egui_menu_choice: "Belum Ada Pilihan".to_string(),
        }
    }

    fn push_device_state(&self) {
        let devices = self.client.list_devices().unwrap_or_default();
        let json = serde_json::to_string(&devices).unwrap_or_default();
        let script = format!("if (window.renderDevices) window.renderDevices({});", json);
        if let Err(e) = self.webview.evaluate_script(&script) {
            eprintln!("[IPC] Gagal mengeksekusi evaluate_script: {:?}", e);
        }
    }
}

impl eframe::App for WebViewSpikeApp {
    fn ui(&mut self, ui: &mut egui::Ui, _frame: &mut eframe::Frame) {
        // Process incoming IPC messages from JavaScript
        let mut state_changed = false;
        while let Ok(msg) = self.ipc_rx.try_recv() {
            match msg {
                AppIpcMsg::ListDevices => {
                    state_changed = true;
                }
                AppIpcMsg::Approve(id) => {
                    let _ = self.client.approve_device(&id);
                    state_changed = true;
                }
                AppIpcMsg::Reject(id) => {
                    let _ = self.client.reject_device(&id);
                    state_changed = true;
                }
            }
        }
        if state_changed {
            self.push_device_state();
        }

        // 1. Top egui Host Bar (Tests Butir d: Overlap & Menu Dropdown above Webview)
        egui::Frame::new()
            .fill(Color32::from_rgb(20, 22, 28))
            .inner_margin(egui::Margin::symmetric(16, 8))
            .show(ui, |ui| {
                ui.horizontal(|ui| {
                    ui.label(
                        RichText::new("HOST EFRAME (egui)")
                            .font(FontId::monospace(12.0))
                            .strong()
                            .color(Color32::from_rgb(52, 211, 153)),
                    );

                    ui.separator();

                    ui.label(
                        RichText::new("Uji Butir d (Tumpang Tindih):")
                            .font(FontId::proportional(12.0))
                            .color(Color32::from_rgb(180, 185, 200)),
                    );

                    // Menu button specifically designed to open downwards OVER the webview area
                    ui.menu_button(
                        RichText::new("▼ Buka Menu Pop-up egui (Tes Overlap)")
                            .font(FontId::proportional(12.0))
                            .color(Color32::WHITE),
                        |ui| {
                            ui.set_min_width(260.0);
                            ui.label(RichText::new("Apakah menu ini tertutup webview?").strong());
                            ui.separator();
                            if ui.button("Opsi 1: Menu Item egui").clicked() {
                                self.egui_menu_choice = "Opsi 1 Dipilih".to_string();
                                ui.close();
                            }
                            if ui.button("Opsi 2: Menu Item egui").clicked() {
                                self.egui_menu_choice = "Opsi 2 Dipilih".to_string();
                                ui.close();
                            }
                        },
                    );

                    ui.label(
                        RichText::new(&self.egui_menu_choice)
                            .font(FontId::monospace(11.0))
                            .color(Color32::from_rgb(251, 191, 36)),
                    );
                });
            });

        ui.add_space(2.0);

        // 2. Central Area allocated for wry WebView (Tests Butir a: Resize & DPI)
        let available_rect = ui.available_rect_before_wrap();
        let ppp = ui.ctx().pixels_per_point();

        // Update webview bounds when window size / rect changes
        if self.last_bounds != Some(available_rect) {
            self.last_bounds = Some(available_rect);

            let x = available_rect.min.x;
            let y = available_rect.min.y;
            let width = available_rect.width().max(10.0);
            let height = available_rect.height().max(10.0);

            let bounds = Rect {
                position: LogicalPosition::new(x as f64, y as f64).into(),
                size: LogicalSize::new(width as f64, height as f64).into(),
            };

            let _ = self.webview.set_bounds(bounds);
        }

        // Placeholder in egui beneath webview
        ui.centered_and_justified(|ui| {
            ui.label(
                RichText::new(format!(
                    "Area WebView wry (DPI: {:.1}x, Bounds: {:.0}x{:.0})",
                    ppp,
                    available_rect.width(),
                    available_rect.height()
                ))
                .font(FontId::monospace(11.0))
                .color(Color32::from_rgb(60, 64, 76)),
            );
        });
    }
}

fn main() -> eframe::Result<()> {
    let native_options = eframe::NativeOptions {
        viewport: egui::ViewportBuilder::default()
            .with_inner_size(Vec2::new(960.0, 620.0))
            .with_min_inner_size(Vec2::new(640.0, 400.0))
            .with_title("Absensi-Cam // Webview Spike (wry in eframe)"),
        ..Default::default()
    };

    eframe::run_native(
        "Absensi-Cam // Webview Spike",
        native_options,
        Box::new(|cc| Ok(Box::new(WebViewSpikeApp::new(cc)))),
    )
}
