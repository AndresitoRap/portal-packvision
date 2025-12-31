import { Routes, Route, useLocation } from "react-router-dom";
import { AnimatePresence } from "framer-motion";
import Home from "./pages/home/Home";
import Facturacion from "./pages/facturacion/Facturacion";
import AnexoODV from "./pages/sap/AnexoODV";
import Admin from "./pages/admin/Admin";

function App() {
  const location = useLocation();

  return (
    <AnimatePresence mode="wait">
      <Routes location={location} key={location.pathname}>
        <Route path="/" element={<Home />} />
        <Route path="/admin" element={<Admin />} />
        <Route path="/facturacion" element={<Facturacion />} />
        <Route path="/anexo" element={<AnexoODV />} />
      </Routes>
    </AnimatePresence>
  );
}
export default App;
