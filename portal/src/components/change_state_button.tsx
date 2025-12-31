import { useState } from "react";
const API_URL = import.meta.env.VITE_API_URL;

type CambiarEstadoFacturaProps = {
  isInvoice: boolean;
};

function CambiarEstadoFactura({ isInvoice }: CambiarEstadoFacturaProps) {
  const [docNum, setDocNum] = useState("");
  const [msg, setMsg] = useState("");

  const cambiarEstado = async () => {
    setMsg("Procesando...");

    try {
      const res = await fetch(
        `${API_URL}/functions/change_state_invoice.php`,
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
          },
          body: JSON.stringify({
            docNum: docNum,
            isInvoice: isInvoice,
          }),
        }
      );

      const data = await res.json();

      if (!data.ok) {
        setMsg("❌ " + data.msg);
      } else {
        setMsg(
          `✅ ${data.tipo} #${docNum} actualizado a no firmado correctamente.`
        );
      }
    } catch (err) {
      setMsg("❌ Error de conexión");
    }
  };

  return (
    <div>
      <input
        type="number"
        placeholder={
          isInvoice
            ? "Número de factura (DocNum)"
            : "Número de nota crédito (DocNum)"
        }
        value={docNum}
        onChange={(e) => setDocNum(e.target.value)}
        style={{ width: "100%", padding: 8 }}
      />

      <button
        onClick={cambiarEstado}
        style={{ marginTop: 10, backgroundColor: "#015081", color: "white" }}
      >
        Cambiar estado
      </button>

      {msg && <p style={{ marginTop: 16 }}>{msg}</p>}
    </div>
  );
}

export default CambiarEstadoFactura;
