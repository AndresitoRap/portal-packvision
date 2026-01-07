import { useState, useEffect } from "react";
import BLogo from "../../assets/BLogo.webp";
import PageTransition from "../../components/page-transition";
import Toast from "../../components/Toast/toast";
import "./Admin.css";
import CambiarEstadoFactura from "../../components/change_state_button";
const API_URL = import.meta.env.VITE_API_URL;
const LAST_TOAST_KEY = "admin_last_toast_time";

type ItemStatus = "pending" | "success" | "error";

interface Factura {
  DocEntry: number;
  DocNum: string;
  prefijo: string;
  folio: number;
  tipo: "FACTURA";
  status?: ItemStatus;
}

interface Nota {
  DocEntry: number;
  DocNum: string;
  prefijo: string;
  folio: number;
  tipo: "NOTA";
  status?: ItemStatus;
}

function Admin() {
  const [facturasPendientes, setFacturasPendientes] = useState<Factura[]>([]);
  const [notasPendientes, setNotasPendientes] = useState<Nota[]>([]);
  const [selectedFactura] = useState<Factura | null>(null);
  const [selectedNota] = useState<Nota | null>(null);
  const [logs, setLogs] = useState<string[]>([]);
  const [statusMsgFactura] = useState("");
  const [statusMsgNota] = useState(""); 
  const [xmlContenido, setXmlContenido] = useState("");
  const [isUnlocked, setIsUnlocked] = useState(false);
  const [passwordInput, setPasswordInput] = useState("");
  const [isRefirmando, setIsRefirmando] = useState(false);

  const [toast, setToast] = useState({
    show: false,
    message: "",
    type: "success" as "success" | "error",
  });

  const getLastToastTime = () => {
    const v = localStorage.getItem(LAST_TOAST_KEY);
    return v ? new Date(v).getTime() : 0;
  };

  const setLastToastTime = (time: string) => {
    localStorage.setItem(LAST_TOAST_KEY, time);
  };

  const showToast = (message: string, type: "success" | "error") => {
    setToast({ show: true, message, type });

    setTimeout(() => {
      setToast((t) => ({ ...t, show: false }));
    }, 4500);
  };

  type DocumentoSeleccionado =
    | (Factura & { tipoDoc: "FACTURA" })
    | (Nota & { tipoDoc: "NOTA" })
    | null;

  const [documentoSeleccionado, setDocumentoSeleccionado] =
    useState<DocumentoSeleccionado>(null);

  useEffect(() => {
    if (!documentoSeleccionado) return;

    const cargarXML = async () => {
      try {
        const res = await fetch(
          `${API_URL}/functions/xml/get_xml.php?tipo=${documentoSeleccionado.tipoDoc}&docEntry=${documentoSeleccionado.DocEntry}`
        );
        const data = await res.json();
        console.log(data);

        if (data.ok) {
          setXmlContenido(data.xml);
        } else {
          setXmlContenido("❌ No se pudo cargar el XML");
        }
      } catch {
        setXmlContenido("❌ Error al cargar XML");
      }
    };

    cargarXML();
  }, [documentoSeleccionado]);

  useEffect(() => {
    setFacturasPendientes([]);
    setNotasPendientes([]);
    setLogs([]);

    const loadLogs = async () => {
      try {
        const res = await fetch(`${API_URL}/functions/process/get_logs.php`);   
        const data = await res.json();

        if (!data.ok) return;

// 🔥 ESTE ES EL PUNTO CLAVE
const orderedLogs = [...data.logs].sort(
  (a, b) => a.id - b.id
);

        // Logs de texto (panel derecho)
        const textLogs: string[] = [];

        const facturas: Factura[] = [];
        const notas: Nota[] = [];

        for (const l of orderedLogs) {
          const time = l.time;
          const type = l.type;
          const payload = l.payload || {};

          // Logs generales
          if (payload.msg) {
            textLogs.push(`[${time}] ${payload.msg}`);
          }

          // Facturas detectadas
          if (type === "factura") {
            const d = payload.data ?? payload;

            facturas.push({
              DocEntry: d.DocEntry,
              DocNum: d.DocNum,
              prefijo: d.prefijo ?? "",
              folio: d.folio ?? 0,
              tipo: "FACTURA",
              status: "pending",
            });
          }

          // Notas detectadas
          if (type === "nota") {
            const d = payload.data ?? payload;

            notas.push({
              DocEntry: d.DocEntry,
              DocNum: d.DocNum,
              prefijo: d.prefijo ?? "",
              folio: d.folio ?? 0,
              tipo: "NOTA",
              status: "pending",
            });
          }

          if (type === "success") {
            if (payload.docType === "FACTURA") {
              facturas.forEach((f) => {
                if (f.DocEntry === payload.DocEntry) {
                  f.status = "success";
                }
              });
            }

            if (payload.docType === "NOTA") {
              notas.forEach((n) => {
                if (n.DocEntry === payload.DocEntry) {
                  n.status = "success";
                }
              });
            }
          }

          if (type === "error") {
            if (payload.docType === "FACTURA") {
              facturas.forEach((f) => {
                if (f.DocEntry === payload.DocEntry) {
                  f.status = "error";
                }
              });
            }

            if (payload.docType === "NOTA") {
              notas.forEach((n) => {
                if (n.DocEntry === payload.DocEntry) {
                  n.status = "error";
                }
              });
            }
          }

          const logTime = new Date(l.time).getTime();
          const lastToastTime = getLastToastTime();

          // 🔔 SOLO SI ES UN EVENTO NUEVO
          if (logTime > lastToastTime) {
            if (type === "success") {
              showToast(payload.msg ?? "Proceso exitoso", "success");
              setLastToastTime(l.time);
            }

            if (type === "error") {
              showToast(payload.msg ?? "Error", "error");
              setLastToastTime(l.time);
            }
          }
        }

        setLogs(textLogs);
        setFacturasPendientes(facturas);
        setNotasPendientes(notas);
      } catch (err) {
        console.error("Error cargando logs", err);
      }
    };

    loadLogs();

    // 🔄 refrescar cada 5 segundos (opcional pero recomendado)
    const interval = setInterval(loadLogs, 5000);
    return () => clearInterval(interval);
  }, []);

  const validarXML = (xml: string): boolean => {
    try {
      const parser = new DOMParser();
      const parsed = parser.parseFromString(xml, "application/xml");

      return !parsed.querySelector("parsererror");
    } catch {
      return false;
    }
  };

  const validarPassword = async () => {
    try {
      const res = await fetch(`${API_URL}/functions/auth/validate_admin.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ password: passwordInput }),
      });

      const data = await res.json();

      if (!data.ok) {
        showToast("❌ Contraseña incorrecta", "error");
        return;
      }

      setIsUnlocked(true);
      setPasswordInput("");
      showToast("✅ Acceso concedido", "success");
    } catch {
      showToast("Error validando contraseña", "error");
    }
  };

  return (
    <PageTransition>
      <a className="volver" href="/">
        Volver
      </a>
      <section className="start">
        <img src={BLogo} alt="Logo" />
        <h1>Panel de facturación</h1>
      </section>

      <section className="Body">
        <div className="left-panel">
          <div className="panel-half">
            <h3>Facturas sin firmar</h3>
            {facturasPendientes.length === 0 && (
              <p>No hay facturas pendientes</p>
            )}
            {facturasPendientes.map((f) => (
              <div
                key={f.DocEntry}
                className={`item ${f.status ?? "pending"} ${
                  f.status === "error" ? "clickable" : ""
                }`}
                onClick={() => {
                  if (f.status === "error") {
                    setDocumentoSeleccionado({ ...f, tipoDoc: "FACTURA" });
                  }
                }}
              >
                {f.prefijo} {f.DocNum}
              </div>
            ))}
          </div>

          <div className="panel-half">
            <h3>Notas crédito sin firmar</h3>
            {notasPendientes.length === 0 && (
              <p>No hay notas crédito pendientes</p>
            )}
            {notasPendientes.map((n) => (
              <div
                key={n.DocEntry}
                className={`item ${n.status ?? "pending"} ${
                  n.status === "error" ? "clickable" : ""
                }`}
                onClick={() => {
                  if (n.status === "error") {
                    setDocumentoSeleccionado({ ...n, tipoDoc: "NOTA" });
                  }
                }}
              >
                {n.prefijo} {n.DocNum}
              </div>
            ))}
          </div>
        </div>

        <div className="logs">
          <div className="logs-header">
            <h3 className="logs-title">
              Historial de movimientos
              <span className="tooltip">
                ℹ️
                <span className="tooltip-text">
                  En el historial de movimientos únicamente se muestras los
                  últimos 200 movimientos que han habido.
                </span>
              </span>
            </h3>
            {selectedFactura && <span>Factura {selectedFactura.DocNum}</span>}
            {selectedNota && <span>Nota {selectedNota.DocNum}</span>}
          </div>

          <div className="logs-body">
            {logs.length === 0 ? (
              <div className="log-empty">No hay historial de movimientos</div>
            ) : (
              <div className="log-list">
                {logs.map((log, idx) => (
                  <div
                    key={idx}
                    className={`log-item ${
                      log.includes("❌")
                        ? "log-error"
                        : log.includes("✅")
                        ? "log-success"
                        : "log-info"
                    }`}
                  >
                    <span className="log-text">{log}</span>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </section>
      <section className="protected-area">
        {!isUnlocked && (
          <div className="auth-overlay">
            <div className="auth-modal">
              <h2>🔐 Acceso restringido</h2>
              <p>Ingrese la contraseña de administrador</p>

              <input
                type="password"
                placeholder="Contraseña"
                value={passwordInput}
                onChange={(e) => setPasswordInput(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter") validarPassword();
                }}
              />

              <button onClick={validarPassword} className="btn-primary">
                Desbloquear
              </button>
            </div>
          </div>
        )}

        <section className="fix-doc">
          <div className="fix-doc-container">
            <div className="fix-doc-panel">
              <h3>Cambiar estado de factura</h3>
              <CambiarEstadoFactura isInvoice={true} />

              {statusMsgFactura && (
                <div className="status-msg">{statusMsgFactura}</div>
              )}
            </div>

            <div className="fix-doc-panel">
              <h3>Cambiar estado de nota crédito</h3>
              <CambiarEstadoFactura isInvoice={false} />
              {statusMsgNota && (
                <div className="status-msg">{statusMsgNota}</div>
              )}
            </div>
          </div>
        </section>

        <section className="edit-xml">
          <div className="footer-line"></div>

          <p className="title-xml">Arreglar XML</p>

          <div className="container-with-xml">
            {documentoSeleccionado && (
              <div className="xml-info">
                <strong>Editando:</strong> {documentoSeleccionado.tipoDoc} –
                DocEntry {documentoSeleccionado.DocEntry}
              </div>
            )}

            {documentoSeleccionado ? (
              <div className="selected-doc">
                <p>
                  <strong>Documento seleccionado:</strong>
                </p>

                <p>
                  Tipo: <strong>{documentoSeleccionado.tipoDoc}</strong>
                </p>

                <p>
                  Número:{" "}
                  <strong>
                    {documentoSeleccionado.prefijo}{" "}
                    {documentoSeleccionado.DocNum}
                  </strong>
                </p>

                <p>
                  DocEntry: <strong>{documentoSeleccionado.DocEntry}</strong>
                </p>

                <textarea
                  value={xmlContenido}
                  onChange={(e) => setXmlContenido(e.target.value)}
                  rows={20}
                  spellCheck={false}
                  style={{
                    width: "100%",
                    fontFamily: "monospace",
                    fontSize: "0.85rem",
                    padding: "1rem",
                    borderRadius: "6px",
                    border: "1px solid #ccc",
                    backgroundColor: "#fff",
                  }}
                />
              </div>
            ) : (
              <p className="hint">
                Selecciona una factura o nota con error para corregir su XML.
              </p>
            )}
          </div>

          <div className="button-wrapper">
            <button
              className="btn-primary"
              onClick={async () => {
                if (!documentoSeleccionado) return;

                if (!validarXML(xmlContenido)) {
                  showToast("❌ El XML no es válido", "error");
                  return;
                }

                setIsRefirmando(true); // 🔒 BLOQUEA LA UI

                try {
                  const res = await fetch(
                    `${API_URL}/functions/xml/refirmar_xml.php`,
                    {
                      method: "POST",
                      headers: { "Content-Type": "application/json" },
                      body: JSON.stringify({
                        xml: xmlContenido,
                        tipo: documentoSeleccionado.tipoDoc,
                        docEntry: documentoSeleccionado.DocEntry,
                        prefijo: "SETT",
                      }),
                    }
                  );

                  const data = await res.json();

                  setDocumentoSeleccionado(null);
                  setXmlContenido("");

                  if (!data.ok) {
                    showToast(`❌ ${data.msg}`, "error");
                    return;
                  }

                  showToast("✅ Documento re-firmado correctamente", "success");
                } catch (err) {
                  showToast("Error enviando XML al servidor", "error");
                } finally {
                  setIsRefirmando(false); // 🔓 SIEMPRE QUITA EL OVERLAY
                }
              }}
            >
              Guardar y re-firmar
            </button>
          </div>
        </section>
      </section>

      <footer className="footer">
          <div className="footer-line"></div>

          <div className="footer-content">
            <p className="footer-text">
              Packvisión® SAS 2025. Todos los derechos reservados
            </p>

            <span className="footer-version">
              v1.0.1
            </span>
          </div>
        </footer>

      {isRefirmando && (
        <div className="refirmando-overlay">
          <div className="refirmando-modal">
            <div className="spinner"></div>
            <h3>Re-firmando documento…</h3>
            <p>Por favor espere, no cierre esta ventana</p>
          </div>
        </div>
      )}

      <Toast show={toast.show} message={toast.message} type={toast.type} />
    </PageTransition>
  );
}

export default Admin;
