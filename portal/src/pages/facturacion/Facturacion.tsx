import "./Facturacion.css";
import BLogo from "../../assets/BLogo.webp";
import { FiSearch, FiFileText } from "react-icons/fi";
import { useState, useEffect } from "react";
import PageTransition from "../../components/page-transition";
const API_URL = import.meta.env.VITE_API_URL;

interface Documento {
  prefijo: string;
  folio: string;
  archivo: string;
  url: string;
}

function Facturacion() {
  const [facturas, setFacturas] = useState<Documento[]>([]);
  const [notas, setNotas] = useState<Documento[]>([]);
  const [loading, setLoading] = useState(true);

  const [searchFactura, setSearchFactura] = useState("");
  const [searchNota, setSearchNota] = useState("");

  const [modalOpen, setModalOpen] = useState(false);
  const [selectedFactura, setSelectedFactura] = useState<any>(null);
  const [loadingFactura, setLoadingFactura] = useState(false);
  const [errorFactura, setErrorFactura] = useState<string | null>(null);

  const [selectedDocumento, setSelectedDocumento] = useState<any>(null);
  const [tipoDocumento, setTipoDocumento] = useState<"factura" | "nota" | null>(
    null
  );

  const openDocumentoModal = async (
    tipo: "factura" | "nota",
    prefijo: string,
    folio: string
  ) => {
    setLoadingFactura(true);
    setErrorFactura(null);
    setSelectedDocumento(null);
    setModalOpen(true);
    setTipoDocumento(tipo);

    try {
      const sapFolio =
        tipo === "factura"
          ? getSapFolio(prefijo, folio)
          : getSapFolio(prefijo, folio);

      const url = `${API_URL}/functions/get_info_document_sap.php?folio=${sapFolio}&tipo=${tipo}`;

      const res = await fetch(url);
      if (!res.ok) throw new Error(`Error en la respuesta: ${res.status}`);
      const data = await res.json();

      if (data.success && data.documento) {
        setSelectedDocumento({
          ...data.documento,
          prefijo,
          folio,
          tipo: tipo === "factura" ? "FACTURA" : "NOTA_CREDITO",
          url: `${API_URL}/pdf/${
            tipo === "factura" ? "FACTURA" : "NOTA_CREDITO"
          }_${prefijo}${folio}.pdf`,
        });
      } else {
        setErrorFactura(data.error || "No se encontró el documento en SAP");
      }
    } catch (err: any) {
      setErrorFactura(err.message || "Error desconocido al traer el documento");
    } finally {
      setLoadingFactura(false);
    }
  };

  // Ajusta folio según prefijo
  const getSapFolio = (prefijo: string, folio: string) => {
    const f = parseInt(folio, 10);
    if (prefijo.toUpperCase() === "FEON")
      return f < 10000 ? 100000 + f : 1000000 + f;
    if (prefijo.toUpperCase() === "FEOC")
      return f < 10000 ? 200000 + f : 2000000 + f;
    if (prefijo.toUpperCase() === "FEPR")
      return f < 10000 ? 5000000 + f : 5000000 + f;
    return f;
  };

  useEffect(() => {
    fetch(`${API_URL}/functions/get_pdfs_folder.php`)
      .then((res) => res.json())
      .then((data) => {
        const facturasMap = (data.facturas || []).map((f: any) => ({
          prefijo: f.prefijo,
          folio: String(f.folio),
          archivo: f.archivo,
          url: f.url || "",
        }));

        const notasMap = (data.notas || []).map((n: any) => ({
          prefijo: n.prefijo,
          folio: String(n.folio),
          archivo: n.archivo,
          url: n.url || "",
        }));

        setFacturas(facturasMap);
        setNotas(notasMap);
        setLoading(false);
      })
      .catch((err) => {
        console.error(err);
        setLoading(false);
      });
  }, []);

  const filteredFacturas = facturas.filter((f) =>
    f.folio.includes(searchFactura)
  );
  const filteredNotas = notas.filter((n) => n.folio.includes(searchNota));


  const monedaLabel = (moneda: string) => {
  switch (moneda) {
    case "$":
    case "COP":
      return "Peso colombiano (COP)";
    case "USD":
      return "Dólar estadounidense (USD)";
    case "EUR":
      return "Euro (EUR)";
    default:
      return moneda;
  }
};


  return (
    <PageTransition>
      <a className="volver" href="/">
        Volver
      </a>

      <section className="start">
        <img src={BLogo} alt="Logo" />
        <h1>Facturación electrónica</h1>
      </section>

      <section className="body">
        {/* FACTURAS */}
        <div className="facturas">
          <h2>Facturas</h2>

          <div className="search">
            <FiSearch className="icon" />
            <input
              type="text"
              placeholder="Buscar factura..."
              value={searchFactura}
              onInput={(e) =>
                setSearchFactura(
                  (e.target as HTMLInputElement).value.replace(/[^0-9]/g, "")
                )
              }
            />
          </div>

          <p className="nota">Buscar solo por folio. Ejemplo: 17809</p>

          <div className="list">
            {loading ? (
              <div className="loader-container">
                <div className="loader"></div>
                <p>Cargando facturas...</p>
              </div>
            ) : filteredFacturas.length > 0 ? (
              filteredFacturas.map((item) => (
                <div
                  key={item.archivo}
                  className="list-item"
                  style={{ cursor: "pointer" }}
                  onClick={() =>
                    openDocumentoModal("factura", item.prefijo, item.folio)
                  }
                >
                  <FiFileText className="item-icon" />
                  <div className="item-info">
                    <h3>
                      {item.prefijo} {item.folio}
                    </h3>
                  </div>
                </div>
              ))
            ) : (
              <p className="no-data">No se encontraron facturas.</p>
            )}
          </div>
        </div>

        {/* NOTAS */}
        <div className="notas">
          <h2>Notas crédito</h2>

          <div className="search">
            <FiSearch className="icon" />
            <input
              type="text"
              placeholder="Buscar Nota..."
              value={searchNota}
              onInput={(e) =>
                setSearchNota(
                  (e.target as HTMLInputElement).value.replace(/[^0-9]/g, "")
                )
              }
            />
          </div>

          <p className="nota">Buscar solo por folio. Ejemplo: 17809</p>

          <div className="list">
            {loading ? (
              <div className="loader-container">
                <div className="loader"></div>
                <p>Cargando notas...</p>
              </div>
            ) : filteredNotas.length > 0 ? (
              filteredNotas.map((item) => (
                <div
                  className="list-item"
                  key={item.archivo}
                  style={{ cursor: "pointer" }}
                  onClick={() =>
                    openDocumentoModal("nota", item.prefijo, item.folio)
                  }
                >
                  <FiFileText className="item-icon" />
                  <div className="item-info">
                    <h3>
                      {item.prefijo} {item.folio}
                    </h3>
                  </div>
                </div>
              ))
            ) : (
              <p className="no-data">No se encontraron notas.</p>
            )}
          </div>
        </div>
      </section>

      <footer className="footer">
        <div className="footer-line"></div>
        <p className="footer-text">
          Packvisión® SAS 2025. Todos los derechos reservados
        </p>
      </footer>

      {modalOpen && (
        <div className="modal">
          <div className="modal-content">
            <button className="close" onClick={() => setModalOpen(false)}>
              ×
            </button>

            {loadingFactura && (
              <>
                <div className="loader"></div>
                <p>Cargando información desde SAP...</p>
              </>
            )}

            {!loadingFactura && selectedDocumento && (
              <>
                <h2>
                  {selectedDocumento.tipo === "FACTURA"
                    ? "Factura"
                    : "Nota crédito"}{" "}
                  {selectedDocumento.prefijo} {selectedDocumento.folio}
                </h2>
                <p>
                  <strong>Fecha:</strong>{" "}
                  {selectedDocumento.DocDate.split("T")[0]}
                </p>
                <p>
                  <strong>Prefijo:</strong> {selectedDocumento.prefijo}
                </p>
                <p>
                  <strong>Folio:</strong> {selectedDocumento.folio}
                </p>

                <p>
                  <strong>Cliente:</strong> {selectedDocumento.CardName}
                </p>
                <p>
                  <strong>NIT:</strong> {selectedDocumento.FederalTaxID}
                </p>
                <p>
                  <strong>Moneda:</strong> {monedaLabel(selectedDocumento.DocCurrency)}

                </p>
                <p>
                  <strong>Total:</strong>{" "}
                  {selectedDocumento.DocTotal.toLocaleString("en-US", {
                    minimumFractionDigits: 2,
                  })}
                </p>
                <p>
                  <strong>Vendedor:</strong> {selectedDocumento.SalesPerson}
                </p>

                <a
                  href={selectedDocumento.url}
                  className="btn-download"
                  download
                  target="_blank"
                  rel="noreferrer"
                >
                  Descargar PDF
                </a>
              </>
            )}

            {!loadingFactura && errorFactura && (
              <p style={{ color: "red" }}>{errorFactura}</p>
            )}
          </div>
        </div>
      )}
    </PageTransition>
  );
}

export default Facturacion;
